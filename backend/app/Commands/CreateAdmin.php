<?php

namespace App\Commands;

use App\Services\AuthService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * The first Super Admin, created on the server by the person it belongs to.
 *
 * There was no way to make one: the seeders create roles and the catalogue but
 * no users (rightly), `uc:demo` refuses in production, and every other path to
 * a staff account needs an existing admin. The password is typed at a hidden
 * prompt here, so it never appears in shell history, in a config file, or in
 * anybody's chat. Production then asks for MFA enrolment at first sign-in.
 */
class CreateAdmin extends BaseCommand
{
    protected $group       = 'Update Center';
    protected $name        = 'uc:create-admin';
    protected $description = 'Create a Super Admin staff account (asks for the password, hidden).';
    protected $usage       = 'uc:create-admin <email> "<Full name>"';

    public function run(array $params)
    {
        $email = $params[0] ?? CLI::prompt('Email');
        $name  = $params[1] ?? CLI::prompt('Full name');

        $pass  = $this->hidden('Password (12+ characters): ');
        $again = $this->hidden('Repeat the password: ');

        if ($pass !== $again) {
            CLI::error('The passwords do not match. Nothing was created.');

            return EXIT_ERROR;
        }

        try {
            $user = (new AuthService())->createUser($email, $pass, $name, ['super_admin'], 'STAFF', true);
        } catch (Throwable $e) {
            CLI::error('Not created: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write('Created Super Admin ' . $user['email'] . ' (' . $user['public_id'] . '). Sign in at /admin.', 'green');

        return EXIT_SUCCESS;
    }

    private function hidden(string $label): string
    {
        CLI::print($label);
        $tty = stream_isatty(STDIN);
        if ($tty) {
            shell_exec('stty -echo');
        }
        $line = rtrim((string) fgets(STDIN), "\r\n");
        if ($tty) {
            shell_exec('stty echo');
        }
        CLI::newLine();

        return $line;
    }
}
