<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('welcome');
Route::view('/login', 'login')->name('login');
Route::view('/register', 'register')->name('register');
Route::view('/home', 'home')->name('home');
Route::view('/umkms', 'umkms')->name('umkms');
Route::view('/umkms/{id}', 'umkm-detail')->whereNumber('id')->name('umkms.show');
Route::view('/umkms/{id}/six-factor-evaluations', 'six-factor-evaluation')->whereNumber('id')->name('umkms.six-factor-evaluations');
Route::view('/form-data', 'form-data')->name('form-data');
Route::view('/factor-profile', 'factor-profile')->name('factor-profile');
Route::view('/distribution', 'distribution')->name('distribution');
Route::view('/comparison', 'comparison')->name('comparison');
Route::view('/admin/analysts', 'admin-analysts')->name('admin.analysts');
Route::view('/admin/transfers', 'admin-transfers')->name('admin.transfers');
