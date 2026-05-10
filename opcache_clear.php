<?php
opcache_reset();
echo 'OPcache cleared.';
@unlink(__FILE__);
