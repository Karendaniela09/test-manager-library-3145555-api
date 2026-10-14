<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BootstrapAdmin extends Command
{
    protected $signature = 'library:bootstrap-admin';

    protected $description = 'Crea el administrador inicial configurado por entorno.';

    public function handle(): int
    {
        $username = mb_strtolower(trim((string) config('services.library.admin_username')));
        $password = (string) config('services.library.admin_password');

        if ($username === '' || $password === '') {
            $this->error('ADMIN_USERNAME y ADMIN_PASSWORD son obligatorios.');

            return self::FAILURE;
        }
        if (mb_strlen($username) > 120) {
            $this->error('ADMIN_USERNAME no puede superar 120 caracteres.');

            return self::FAILURE;
        }

        $created = DB::table('library.user')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'username' => $username,
            'password_hash' => Hash::make($password),
            'role' => 'admin',
        ]);

        $this->info($created === 1 ? 'Administrador inicial creado.' : 'El administrador ya existe.');

        return self::SUCCESS;
    }
}
