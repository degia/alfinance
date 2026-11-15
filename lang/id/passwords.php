<?php

/*
|--------------------------------------------------------------------------
| Pesan Reset Kata Sandi
|--------------------------------------------------------------------------
|
| `user` sengaja tidak pernah sampai ke pengguna: lihat
| {@see \App\Http\Responses\GenericPasswordResetLinkRequestResponse} yang
| menukar balasan "email tidak terdaftar" dengan `sent` supaya halaman lupa
| sandi tidak bisa dipakai memetakan email mana yang punya akun. Barisnya
| tetap diterjemahkan karena broker masih memakainya di jalur lain.
|
*/

return [

    'reset' => 'Kata sandi kamu berhasil diubah.',
    'sent' => 'Kalau email tersebut terdaftar, kami sudah mengirim tautan untuk mengatur ulang kata sandi.',
    'throttled' => 'Tunggu sebentar sebelum mencoba lagi.',
    'token' => 'Tautan atur ulang kata sandi ini tidak valid atau sudah kedaluwarsa.',
    'user' => 'Kami tidak menemukan akun dengan email tersebut.',

];
