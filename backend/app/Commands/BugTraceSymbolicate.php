<?php

namespace App\Commands;

use App\Libraries\BugTrace\Bt;
use App\Services\BugTrace\SymbolService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Re-symbolicates events whose source maps were uploaded after they arrived.
 * Usage: php spark bugtrace:symbolicate [release_public_id|version]
 */
class BugTraceSymbolicate extends BaseCommand
{
    protected $group       = 'BugTrace';
    protected $name        = 'bugtrace:symbolicate';
    protected $description = 'Re-symbolicate Bug Trace events with missing/partial symbols.';
    protected $usage       = 'bugtrace:symbolicate [release]';

    public function run(array $params)
    {
        $svc = new SymbolService();
        $ref = $params[0] ?? null;
        $n   = 0;
        if ($ref !== null) {
            $rel = Bt::db()->table('releases')->where(str_starts_with($ref, 'rel_') ? 'public_id' : 'version', $ref)->get()->getRowArray();
            $n   = $svc->resymbolicate($rel ? (int) $rel['id'] : null, $rel ? null : $ref);
        } else {
            foreach (Bt::db()->table('bt_symbols')->select('release_id, release')->distinct()->where('uploaded_at >=', gmdate('Y-m-d H:i:s', time() - 86400))->get()->getResultArray() as $s) {
                $n += $svc->resymbolicate($s['release_id'] !== null ? (int) $s['release_id'] : null, $s['release']);
            }
        }
        CLI::write("bugtrace:symbolicate — {$n} events updated");
    }
}
