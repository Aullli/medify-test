<?php

namespace App\Http\Controllers;

use App\Models\MasterItem;
use App\Models\MasterItemsCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Facades\Excel;

class MasterItemsController extends Controller
{
    public function index()
    {
        return view('master_items.index.index');
    }

    public function search(Request $request)
    {
        // Bersihkan format angka (mis. "1.000" -> "1000") sebelum divalidasi
        $request->merge([
            'hargamin' => $this->digitsOrNull($request->hargamin),
            'hargamax' => $this->digitsOrNull($request->hargamax),
        ]);

        $request->validate([
            'kode'     => 'nullable|string|max:50',
            'nama'     => 'nullable|string|max:255',
            'hargamin' => 'nullable|numeric|min:0',
            'hargamax' => 'nullable|numeric|min:0',
        ]);

        $kode     = $request->kode;
        $nama     = $request->nama;
        $hargamin = $request->hargamin;
        $hargamax = $request->hargamax;

        // Eager loading kategori (many to many)
        $data_search = MasterItem::query()
            ->with('categories:master_items_categories.id,master_items_categories.nama');

        if (!empty($kode)) $data_search->where('kode', $kode);
        if (!empty($nama)) $data_search->where('nama', 'LIKE', '%' . $this->escapeLike($nama) . '%');

        // FIX BUG: harga min & max dipisah supaya bisa dipakai sendiri-sendiri
        if ($hargamin !== null && $hargamin !== '') $data_search->where('harga_beli', '>=', $hargamin);
        if ($hargamax !== null && $hargamax !== '') $data_search->where('harga_beli', '<=', $hargamax);

        // 'id' wajib ikut di-select agar relasi categories bisa dimuat
        $data_search = $data_search
            ->select('id', 'kode', 'nama', 'jenis', 'harga_beli', 'laba', 'supplier', 'foto')
            ->orderBy('id')
            ->get();

        return response()->json([
            'status' => 200,
            'data' => $data_search
        ]);
    }

    public function formView($method, $id = 0)
    {
        if ($method == 'new') {
            $item = [];
        } else {
            $item = MasterItem::with('categories')->findOrFail($id);
        }
        $data['item'] = $item;
        $data['method'] = $method;
        $data['categories'] = MasterItemsCategory::orderBy('nama')->get(['id', 'nama', 'kode']);
        return view('master_items.form.index', $data);
    }

    public function singleView($kode)
    {
        $data['data'] = MasterItem::with('categories')->where('kode', $kode)->firstOrFail();
        return view('master_items.single.index', $data);
    }

    public function formSubmit(Request $request, $method, $id = 0)
    {
        $request->validate([
            'nama'         => 'required|string|max:255',
            'harga_beli'   => 'required|numeric|min:0',
            'laba'         => 'required|numeric|min:0|max:100',
            'supplier'     => 'required|string|max:255',
            'jenis'        => 'required|string|max:100',
            // Hanya gambar raster; SVG sengaja tidak diizinkan (risiko XSS)
            'foto'         => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'categories'   => 'nullable|array',
            'categories.*' => 'integer|exists:master_items_categories,id',
        ]);

        DB::transaction(function () use ($request, $method, $id) {
            if ($method == 'new') {
                $data_item = new MasterItem;
                // withTrashed(): item yang di-soft-delete tetap dihitung supaya kode tidak bentrok
                $kode = (MasterItem::withTrashed()->lockForUpdate()->max('id') ?? 0) + 1;
                $kode = str_pad($kode, 5, '0', STR_PAD_LEFT);
            } else {
                $data_item = MasterItem::findOrFail($id);
                $kode = $data_item->kode;
            }

            $data_item->nama = $request->nama;
            $data_item->harga_beli = $request->harga_beli;
            $data_item->laba = $request->laba;
            $data_item->kode = $kode;
            $data_item->supplier = $request->supplier;
            $data_item->jenis = $request->jenis;

            // Upload foto -> storage/app/public/items/<nama-acak>
            if ($request->hasFile('foto')) {
                $fotoLama = $data_item->foto;
                $data_item->foto = $request->file('foto')->store('items', 'public');
                if ($fotoLama) Storage::disk('public')->delete($fotoLama);
            }

            $data_item->save();

            // Simpan relasi many to many kategori
            $data_item->categories()->sync($request->input('categories', []));
        });

        return redirect('master-items');
    }

    public function delete($id)
    {
        // Soft delete: foto & relasi kategori dibiarkan supaya data bisa di-restore
        MasterItem::findOrFail($id)->delete();
        return redirect('master-items');
    }

    /**
     * Download Master Items (xlsx):
     * No | Nama Kategori (pisah koma) | Nama Items | Nama Supplier | Harga | Laba | Harga Jual
     */
    public function export()
    {
        $export = new class implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize {
            private int $no = 0;

            public function query()
            {
                return MasterItem::query()
                    ->with('categories:master_items_categories.id,master_items_categories.nama')
                    ->orderBy('id');
            }

            public function headings(): array
            {
                return ['No', 'Nama Kategori', 'Nama Items', 'Nama Supplier', 'Harga', 'Laba', 'Harga Jual'];
            }

            public function map($item): array
            {
                // Laba dalam persen
                $hargaJual = $item->harga_beli + ($item->harga_beli * $item->laba / 100);

                return [
                    ++$this->no,
                    $this->safe($item->categories->pluck('nama')->implode(', ')),
                    $this->safe($item->nama),
                    $this->safe($item->supplier),
                    $item->harga_beli,
                    $item->laba,
                    $hargaJual,
                ];
            }

            // Cegah formula injection di Excel (= + - @ di awal sel)
            private function safe($value)
            {
                if (is_string($value) && preg_match('/^[=+\-@\t\r]/', $value)) {
                    return "'" . $value;
                }
                return $value;
            }
        };

        return Excel::download($export, 'master-items-' . now()->format('Ymd_His') . '.xlsx');
    }

    public function updateRandomData()
    {
        // Endpoint dummy: hanya boleh jalan di local
        abort_unless(app()->environment('local'), 404);

        $data = MasterItem::get();
        foreach($data as $item)
        {
            $kode = $item->id;
            $kode = str_pad($kode, 5, '0', STR_PAD_LEFT);

            $item->harga_beli = rand(100,1000000);
            $item->laba = rand(10,99);
            $item->kode = $kode;
            $item->supplier = $this->getRandomSupplier();
            $item->jenis = $this->getRandomJenis();
            $item->save();
        }
    }

    private function getRandomSupplier()
    {
        $array = ['Tokopaedi','Bukulapuk','TokoBagas','E Commurz','Blublu'];
        $random = rand(0,4);
        return $array[$random];
    }

    private function getRandomJenis()
    {
        $array = ['Obat','Alkes','Matkes','Umum','ATK'];
        $random = rand(0,4);
        return $array[$random];
    }

    private function digitsOrNull($value)
    {
        if ($value === null || $value === '') return null;
        $clean = preg_replace('/\D/', '', (string) $value);
        return $clean === '' ? null : $clean;
    }

    private function escapeLike($value)
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
