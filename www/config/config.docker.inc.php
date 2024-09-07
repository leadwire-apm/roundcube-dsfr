<?php
  $config['db_dsnw'] = 'pgsql://roundcube:roundcube@roundcubedb:5432/roundcube';
  $config['db_dsnr'] = '';
  $config['default_host'] = 'tls://mailserver.leadwire.consulting';
  $config['default_port'] = '143';
  $config['smtp_server'] = 'tls://mailserver.leadwire.consulting';
  $config['smtp_port'] = '587';
  $config['temp_dir'] = '/tmp/roundcube-temp';
  $config['skin'] = 'ElasticDSFR0.5';
  $config['plugins'] = array_filter(array_unique(array_merge($config['plugins'], ['archive', 'zipdownload'])));
  
