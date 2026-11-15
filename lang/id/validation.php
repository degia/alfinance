<?php

/*
|--------------------------------------------------------------------------
| Pesan Validasi
|--------------------------------------------------------------------------
|
| Berkas ini sengaja TIDAK memuat seluruh aturan bawaan Laravel, melainkan
| hanya aturan yang benar-benar dipakai di jalur yang pesannya datang dari
| framework — terutama alur autentikasi Fortify (masuk, daftar, lupa sandi,
| atur ulang sandi, konfirmasi sandi).
|
| Validasi milik aplikasi sendiri sudah menulis pesannya masing-masing lewat
| `FormRequest::messages()` (lihat `app/Http/Requests`), jadi menyalin ratusan
| baris aturan ke sini hanya akan menciptakan terjemahan mati yang tidak
| pernah tampil dan ikut usang saat Laravel diperbarui.
|
| Kunci yang tidak ada di sini jatuh ke `APP_FALLBACK_LOCALE` (en) — perilaku
| yang sama seperti sebelum berkas ini ada, jadi tidak ada yang memburuk.
|
*/

return [

    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'current_password' => 'Kata sandi salah.',
    'email' => 'Format :attribute tidak valid.',
    'in' => ':attribute yang dipilih tidak valid.',
    'max' => [
        'numeric' => ':attribute maksimal :max.',
        'string' => ':attribute maksimal :max karakter.',
    ],
    'min' => [
        'numeric' => ':attribute minimal :min.',
        'string' => ':attribute minimal :min karakter.',
    ],
    'password' => [
        'letters' => ':attribute harus mengandung minimal satu huruf.',
        'mixed' => ':attribute harus mengandung huruf besar dan huruf kecil.',
        'numbers' => ':attribute harus mengandung minimal satu angka.',
        'symbols' => ':attribute harus mengandung minimal satu simbol.',
        'uncompromised' => ':attribute pernah bocor di kebocoran data. Pilih kata sandi lain.',
    ],
    'required' => ':attribute wajib diisi.',
    'same' => ':attribute dan :other harus sama.',
    'string' => ':attribute harus berupa teks.',
    'unique' => ':attribute sudah terdaftar.',

    /*
    |--------------------------------------------------------------------------
    | Nama Atribut
    |--------------------------------------------------------------------------
    |
    | Tanpa ini, pesan di atas akan menyebut nama kolom mentah ("email address",
    | "password") di tengah kalimat berbahasa Indonesia.
    |
    */

    'attributes' => [
        'code' => 'kode',
        'current_password' => 'kata sandi saat ini',
        'email' => 'email',
        'name' => 'nama',
        'password' => 'kata sandi',
        'password_confirmation' => 'konfirmasi kata sandi',
        'recovery_code' => 'kode pemulihan',
        'token' => 'token',
    ],

];
