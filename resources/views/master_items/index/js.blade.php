{{-- resources/views/master_items/index/js.blade.php --}}
<script src="https://code.jquery.com/jquery-3.5.1.js"></script>
<script src="https://cdn.datatables.net/1.12.1/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.12.1/js/dataTables.bootstrap5.min.js"></script>

<script>
    var baseUrl = '{{ url("master-items") }}';
    var storageUrl = '{{ asset("storage") }}';

    $(document).ready(function() {
        $('#table').DataTable({
            searching: false,
            order: [[0, 'desc']],
            columnDefs: [
                { orderable: false, targets: [6, 7, 8] } // Kategori, Foto, Aksi
            ],
        });
        getData()
    });

    $('.btn-get-data').click(function() {
        getData()
    })

    // Escape HTML supaya data dari database tidak bisa disisipi script (XSS)
    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function rupiah(angka) {
        return Number(angka).toLocaleString('id-ID');
    }

    function getData() {
        $('#loading-filter').show();
        var dataTableObj = $('#table').DataTable();
        dataTableObj.clear().draw();

        $.ajax({
            url: baseUrl + '/search',
            dataType: 'json',
            tryCount: 0,
            retryLimit: 3,
            // Pakai object supaya nilai otomatis di-encode (aman untuk karakter khusus)
            data: {
                kode: $('#filter-kode').val(),
                nama: $('#filter-nama').val(),
                hargamin: $('#filter-harga-min').val(),
                hargamax: $('#filter-harga-max').val()
            },
            success: function(results) {
                var rows = [];

                $.each(results.data, function(index, item) {
                    var hargaBeli = Number(item.harga_beli);
                    var hargaJual = Math.round(hargaBeli + hargaBeli * Number(item.laba) / 100);

                    // Kolom dibuat eksplisit (tidak bergantung urutan field JSON)
                    var kategori = (item.categories || []).map(function(c) { return esc(c.nama); }).join(', ');

                    var foto = item.foto
                        ? '<img src="' + storageUrl + '/' + encodeURI(item.foto) + '" width="50" alt="foto">'
                        : '-';

                    var aksi =
                        '<a href="' + baseUrl + '/view/' + encodeURIComponent(item.kode) + '" class="btn btn-sm btn-primary me-1">View</a>' +
                        '<a href="' + baseUrl + '/form/edit/' + item.id + '" class="btn btn-sm btn-warning me-1">Edit</a>' +
                        '<a href="' + baseUrl + '/delete/' + item.id + '" class="btn btn-sm btn-danger" ' +
                        'onclick="return confirm(\'Hapus item ini?\')">Hapus</a>';

                    rows.push([
                        esc(item.kode),
                        esc(item.nama),
                        esc(item.jenis),
                        rupiah(hargaBeli),
                        rupiah(hargaJual),
                        esc(item.supplier),
                        kategori || '-',
                        foto,
                        aksi
                    ]);
                });

                dataTableObj.rows.add(rows).draw();
                $('#loading-filter').hide();
            },
            error: function(xhr, textStatus, errorThrown) {
                this.tryCount++;
                if (this.tryCount <= this.retryLimit) {
                    $.ajax(this);
                    return;
                }
                alert('Terjadi kesalahan server, tidak dapat mengambil data')
                $('#loading-filter').hide();

                return;
            }
        })
    }
</script>
