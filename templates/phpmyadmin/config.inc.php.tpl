<?php
declare(strict_types=1);

/**
 * LiteControl - phpMyAdmin Configuration
 */
$cfg['blowfish_secret'] = '__BLOWFISH_SECRET__';

$i = 0;
$i++;

/* Authentication type */
$cfg['Servers'][$i]['auth_type'] = 'cookie';
/* Server parameters */
$cfg['Servers'][$i]['host'] = 'localhost';
$cfg['Servers'][$i]['compress'] = false;
$cfg['Servers'][$i]['AllowNoPassword'] = false;

/* Directories for saving/loading files from server */
$cfg['UploadDir'] = '';
$cfg['SaveDir'] = '';
$cfg['TempDir'] = '/usr/local/litecontrol/phpmyadmin/tmp';

/* Security & UI tweaks */
$cfg['VersionCheck'] = false;
$cfg['ShowPhpInfo'] = false;
$cfg['LoginCookieValidity'] = 3600;

