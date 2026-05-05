    </div><!-- /.content-area -->
</div><!-- /.main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.getElementById('sidebarToggle')?.addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('show');
    });
</script>
<?php if (isset($extraScript)) echo $extraScript; ?>
</body>
</html>
