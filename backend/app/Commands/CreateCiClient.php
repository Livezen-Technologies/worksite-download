<?php

namespace App\Commands;

use App\Libraries\Rbac;
use App\Services\AuthService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * A CI API client for scripts/publish-release.mjs, created on the server.
 *
 * Permissions are the CI defaults plus release.edit (to submit for testing).
 * Never release.publish: production refuses publishing without a person and
 * MFA anyway. The one-time token is written to a file (mode 600), not printed,
 * so it does not land in a terminal scrollback or a log.
 */
class CreateCiClient extends BaseCommand
{
    protected $group       = 'Update Center';
    protected $name        = 'uc:create-ci-client';
    protected $description = 'Create a CI API client for the publish script; the token is written to a file.';
    protected $usage       = 'uc:create-ci-client "<name>" <token-file> [ip-or-cidr ...]';

    public function run(array $params)
    {
        $name = $params[0] ?? null;
        $file = $params[1] ?? null;
        if (! $name || ! $file) {
            CLI::error('Usage: ' . $this->usage);

            return EXIT_ERROR;
        }
        if (file_exists($file)) {
            CLI::error("{$file} already exists. Nothing was created.");

            return EXIT_ERROR;
        }

        $permissions = array_values(array_unique(array_merge(Rbac::CI_DEFAULT, ['release.edit'])));

        try {
            $created = (new AuthService())->createApiClient($name, 'CI', $permissions, array_slice($params, 2));
        } catch (Throwable $e) {
            CLI::error('Not created: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        $old = umask(0077);
        file_put_contents($file, $created['secret'] . "\n");
        umask($old);
        chmod($file, 0600);

        CLI::write("Created CI client {$created['client']['client_id']} ({$created['client']['public_id']}); token written to {$file}.", 'green');
        CLI::write('Permissions: ' . implode(', ', $permissions));

        return EXIT_SUCCESS;
    }
}
