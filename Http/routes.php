<?php

Route::group([
    'middleware' => ['web', 'auth', 'roles'],
    'prefix' => \Helper::getSubdirectory(),
    'namespace' => 'Modules\Repile\Http\Controllers',
], function () {
    Route::get('/repile/conversations/{id}/state', 'PanelController@state')->name('repile.state');
    Route::post('/repile/conversations/{id}/recheck', 'PanelController@recheck')->name('repile.recheck');
    Route::post('/repile/test', ['uses' => 'PanelController@test', 'roles' => ['admin']])->name('repile.test');
});

Route::group([
    'prefix' => \Helper::getSubdirectory().'/repile/api',
    'namespace' => 'Modules\Repile\Http\Controllers',
    'middleware' => [\Modules\Repile\Http\Middleware\ApiKey::class],
], function () {
    Route::get('/mailboxes', 'ApiController@mailboxes');
    Route::get('/conversations', 'ApiController@conversations');
    Route::get('/conversations/{id}', 'ApiController@conversation')->where('id', '[0-9]+');
    Route::put('/conversations/{id}', 'ApiController@updateConversation')->where('id', '[0-9]+');
    Route::post('/conversations/{id}/threads', 'ApiController@createThread')->where('id', '[0-9]+');
    Route::post('/statuses', 'ApiController@statuses');
});
