<script>
    $(function () {
        @foreach($script as $s)
        try {
            {!! $s !!}
        } catch (error) {
            window.console && window.console.error('Admin script failed:', error);
        }
        @endforeach
    });
</script>
