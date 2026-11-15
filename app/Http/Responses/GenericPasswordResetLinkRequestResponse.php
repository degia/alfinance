<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * Balasan seragam untuk permintaan tautan atur ulang kata sandi.
 *
 * Bawaan Fortify menjawab "Kami tidak menemukan akun dengan email tersebut"
 * ketika email tidak terdaftar, sehingga halaman lupa sandi bisa dipakai siapa
 * pun untuk menguji satu per satu email mana yang punya akun di sini. Karena
 * halaman ini terbuka untuk tamu dan tidak butuh kredensial apa pun, itu jalur
 * enumerasi akun yang murah.
 *
 * Kelas ini menukar balasan gagal "pengguna tidak ditemukan" dengan balasan
 * sukses yang sama persis — isi, status HTTP, dan tujuan redirect — sehingga
 * penyerang tidak bisa membedakan keduanya.
 *
 * Kegagalan lain (mis. throttle) tetap dilaporkan apa adanya: itu tentang laju
 * permintaan si peminta sendiri, bukan tentang keberadaan akun orang lain.
 */
class GenericPasswordResetLinkRequestResponse implements FailedPasswordResetLinkRequestResponseContract
{
    public function __construct(protected string $status) {}

    public function toResponse($request): Response
    {
        $message = trans($this->isAccountProbe() ? Password::RESET_LINK_SENT : $this->status);

        if ($request->wantsJson()) {
            return new JsonResponse(['message' => $message], 200);
        }

        return back()->with('status', $message);
    }

    /**
     * Apakah kegagalan ini membocorkan ada/tidaknya sebuah akun?
     */
    private function isAccountProbe(): bool
    {
        return $this->status === Password::INVALID_USER;
    }
}
