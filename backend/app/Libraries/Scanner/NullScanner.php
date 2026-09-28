<?php

namespace App\Libraries\Scanner;

final class NullScanner implements MalwareScannerInterface
{
    public function scan($stream): array
    {
        return ['status' => 'SKIPPED', 'detail' => 'No malware scanner configured'];
    }
}
