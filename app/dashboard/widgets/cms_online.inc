<?php


function widget_cms_online() {
  
  
  return array(
    'title' => utf8_encode('Audi�ncia'),
    'content' => '',
    'classes' => 'search',
    'fullscreen' => '<strong>Some fullscreen stuff</strong>',
	'initScript' => 'widgets/scripts/cms_OnLineReloadContent.js',
	'reloadContentScript' => 'widgets/scripts/cms_OnLineReloadContent.js'
  );
}

