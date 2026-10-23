@if(Session::has('toastr'))
    @php
        $toastr = admin_toastr_payload(Session::pull('toastr'));
        $type = in_array($toastr['type'], ['success', 'info', 'warning', 'error'], true) ? $toastr['type'] : 'success';
    @endphp
    <script>
        $(function () {
            toastr.{{ $type }}(@json($toastr['message']), null, @json($toastr['options']));
        });
    </script>
@endif
