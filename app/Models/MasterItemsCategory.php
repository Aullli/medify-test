<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MasterItemsCategory extends Model
{
    use HasFactory;

    protected $table = 'master_items_categories';
    protected $fillable = ['nama', 'kode'];

    public function items()
    {
        return $this->belongsToMany(
            MasterItem::class,
            'master_items_category_master_item',
            'category_id',
            'master_item_id'
        );
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
}