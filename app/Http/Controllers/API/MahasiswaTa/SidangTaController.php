<?php

namespace App\Http\Controllers\API\MahasiswaTa;

use App\Http\Controllers\Controller;
use App\Models\Bimbingan;
use App\Models\Ta;
use App\Models\TaSidang;
use App\Models\mahasiswa;
use App\Models\KodeProdi;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Mccarlosen\LaravelMpdf\Facades\LaravelMpdf;
use Mpdf;
use Mpdf\Mpdf as MpdfMpdf;

// Mahasiswa
class SidangTaController extends Controller
{
    public function index()
    {
        $id = Auth::user()->id;
        $mahasiswa = Bimbingan::Mahasiswa($id);

        if (!isset($mahasiswa)) {
            return response()->json([
                'success' => false,
                'message' => 'Mahasiswa tidak ditemukan'
            ], 404);
        }

        Carbon::setLocale('id');
        $tanggal_sidang = Carbon::parse($mahasiswa->tgl_sidang)->translatedFormat('l, j F Y');
        $hari_sidang = Carbon::parse($mahasiswa->tgl_sidang)->translatedFormat('l');

        $dataTa = Ta::dataTa($id);
        $dosenNip = Bimbingan::Mahasiswa($id)->dosen_nip;
        $dosenNama = Bimbingan::Mahasiswa($id)->dosen_nama;
        $bimbinganId = Bimbingan::Mahasiswa($id)->bimbingan_id;

        if (!isset($dataTa)) {
            return response()->json([
                'success' => false,
                'message' => 'Isikan Judul Proposal Terlebih Dahulu'
            ], 400);
        } elseif (!isset($dosenNama, $dosenNip, $bimbinganId)) {
            return response()->json([
                'success' => false,
                'message' => 'Anda Belum Mendapatkan Dosen Pembimbing'
            ], 400);
        } else {
            $taSidang = TaSidang::where('ta_id', $dataTa->ta_id)->first();
            return response()->json([
                'success' => true,
                'data' => [
                    'mahasiswa' => $mahasiswa,
                    'taSidang' => $taSidang,
                    'tanggal_sidang' => $tanggal_sidang,
                    'hari_sidang' => $hari_sidang
                ]
            ]);
        }
    }

    public function suratTugas()
    {

    }

    public function store(Request $request)
    {
        $id = Auth::user()->id;
        $taId = Bimbingan::Mahasiswa($id)->ta_id;

        $validator = Validator::make($request->all(), [
            'draft_revisi' => 'required|mimetypes:application/pdf|max:2048'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'File gagal diupload. Periksa kembali data anda.',
                'errors' => $validator->errors()
            ], 400);
        }

        $taSidang = TaSidang::where('ta_id', $taId)->first();

        if ($taSidang) {
            $fileLama = public_path('storage/draft_revisi/' . $taSidang->revisi_file);
            if (file_exists($fileLama) && !empty($taSidang->revisi_file)) {
                unlink($fileLama);
            }
        }

        try {
            $draft_revisi = $request->file('draft_revisi');
            $revisi_file = date('Ymdhis') . '.' . $draft_revisi->getClientOriginalExtension();
            $draft_revisi->storeAs('public/draft_revisi', $revisi_file);

            TaSidang::updateOrCreate(
                ['ta_id' => $taId],
                [
                    'revisi_file_original' => $draft_revisi->getClientOriginalName(),
                    'revisi_file' => $revisi_file,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'File revisi berhasil diupload'
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Terdapat masalah di server: ' . $th->getMessage()
            ], 500);
        }
    }

    public function cetak_lembar_pengesahan_api() {
        $mhs = mahasiswa::where("email", Auth::user()->email)->first();
        $ta_mhs = collect(DB::select("SELECT mhs_nim FROM tas_mahasiswa WHERE ta_id IN (SELECT ta_id FROM tas_mahasiswa WHERE mhs_nim = '" . $mhs->mhs_nim . "') ORDER BY ta_id ASC"));
        // $ta_mhs = collect(DB::select("SELECT mhs_nim FROM tas WHERE ta_id IN (SELECT ta_id FROM tas WHERE mhs_nim = '" . $mhs->mhs_nim . "') ORDER BY ta_id ASC"));
        $prodi_id = $mhs->prodi_ID;
        $ta = DB::selectOne("SELECT * FROM tas WHERE mhs_nim = '" . $mhs->mhs_nim . "'");
        $prodi = KodeProdi::where("prodi_ID", $prodi_id)->first();
        
        $infoujian = Ta::dataujian($ta->ta_id);
        // dd($infoujian);

        $datamhs = Ta::where('ta_id', $ta->ta_id)->first();
        $nim = $mhs->mhs_nim;
        $depan_jenjang = substr($nim, 0, 1);
        if ($depan_jenjang == '3')
            $jen = "D3";
        elseif ($depan_jenjang == '4')
            $jen = "D4";

        $belakang_jenjang = substr($nim, 1, 2);
        if ($belakang_jenjang == '33') {
            $prod = "TI";
            $kelasnya = "4";
        } elseif ($belakang_jenjang == '34') {
            $prod = "IK";
            $kelasnya = "3";
        }

        $kode_prodi = $jen . $prod;

        $tahunAjaran = Ta::CekTahunAjaran();
        $noSk = Ta::NoSk($tahunAjaran->ta, $kode_prodi);
        // dd($noSk);
        $temp = [];
        foreach ($ta_mhs->pluck('mhs_nim') as $row) {
            $temp[] = $row;
        }

        $mahasiswa = DB::select("SELECT * FROM mahasiswa WHERE mhs_nim IN (" . implode(",", $temp) . ")");

        $pembimbings = collect(DB::select("SELECT D.file_ttd, D.dosen_nama, B.* FROM bimbingans B JOIN dosen D ON B.dosen_nip = D.dosen_nip WHERE ta_id = " . $ta->ta_id));

        $pembimbing = [];
        $bimb_id = [];
        $approve = "";
        foreach ($pembimbings as $row) {
            $bimb_id[] = $row->bimbingan_id;
            $pembimbing[] = [
                "nama" => $row->dosen_nama,
                "nip" => $row->dosen_nip,
                "ttd" => $row->file_ttd
            ];
        }
        // dd($infoujian);

        $tgl = DB::selectOne("SELECT MAX(bimb_tgl) tgl FROM bimbingan_log WHERE bimbingan_id IN (" . implode(",", $bimb_id) . ")");

        $jenis = ["1" => "Tugas Akhir", "2" => "Skripsi"];

        Carbon::setLocale('id');
        // dd($noSk);
        if (!$pembimbing[0]['ttd'] || !file_exists(public_path('dist/img/' . $pembimbing[0]['ttd']))) {
            return redirect()->route('sidang-tugas-akhir')->with('error', 'File tanda tangan tidak ditemukan untuk dosen: ' . $pembimbing[0]['nama']);
        }

        if (!$pembimbing[1]['ttd'] || !file_exists(public_path('dist/img/' . $pembimbing[1]['ttd']))) {
            return redirect()->route('sidang-tugas-akhir')->with('error', 'File tanda tangan tidak ditemukan untuk dosen: ' . $pembimbing[1]['nama']);
        }

        if (!$infoujian->penguji1_ttd_path || !file_exists(public_path('dist/img/' . $infoujian->penguji1_ttd_path))) {
            return redirect()->route('sidang-tugas-akhir')->with('error', 'File tanda tangan tidak ditemukan untuk dosen: ' . $infoujian->penguji1_nama);
        }

        if (!$infoujian->penguji2_ttd_path || !file_exists(public_path('dist/img/' . $infoujian->penguji2_ttd_path))) {
            return redirect()->route('sidang-tugas-akhir')->with('error', 'File tanda tangan tidak ditemukan untuk dosen: ' . $infoujian->penguji2_nama);
        }

        if (!$infoujian->penguji3_ttd_path || !file_exists(public_path('dist/img/' . $infoujian->penguji3_ttd_path))) {
            return redirect()->route('sidang-tugas-akhir')->with('error', 'File tanda tangan tidak ditemukan untuk dosen: ' . $infoujian->penguji3_nama);
        }

        if (!$noSk->file_ttd || !file_exists(public_path('dist/img/' . $noSk->file_ttd))) {
            return redirect()->route('sidang-tugas-akhir')->with('error', 'File tanda tangan tidak ditemukan untuk dosen: ' . $noSk->nama_kajur);
        }

        $mpdf = new \Mpdf\Mpdf();
        $view = view("cetak-cetak.lembar-pengesahan", [
            "jenis" => $jenis,
            "prodi_id" => $prodi_id,
            "prodi_nama" => $prodi->program_studi,
            "mahasiswa" => $mahasiswa,
            "judul_ta" => $infoujian->judul_final,
            "tanggal_approve" => Carbon::now()->translatedFormat('j F Y'),
            "pembimbing" => $pembimbing,
            "infoujian" => $infoujian,
            "infokajur" => $noSk,
        ]);
        
        $mpdf->WriteHTML($view);

        // Simpan PDF ke file sementara
        $fileName = 'Lembar_Pengesahan_' . ucwords(strtolower($mhs->mhs_nama)) . '.pdf';
        $filePath = storage_path('app/public/' . $fileName);
        $mpdf->Output($filePath, \Mpdf\Output\Destination::FILE);

        // Kembalikan respons dengan file PDF
        return response()->download($filePath)->deleteFileAfterSend(true);
    }
}
