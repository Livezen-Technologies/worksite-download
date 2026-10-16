<?php

namespace Tests\Feature;

use App\Services\PublicCatalogService;
use Tests\Support\UcTestCase;

/** Beta made public by an administrator is offered to everyone (downloads, changelog, update checks). */
final class PublicBetaTest extends UcTestCase
{
    public function testBetaIsHiddenFromVisitorsUntilAnAdministratorMakesItPublic(): void
    {
        $db = db_connect();
        $db->table('release_channels')->where('code', 'BETA')->where('application_id', null)->update(['is_public' => 0]);
        $this->assertSame(['STABLE'], (new PublicCatalogService())->visibleChannelCodes());

        $db->table('release_channels')->where('code', 'BETA')->where('application_id', null)->update(['is_public' => 1]);
        $codes = (new PublicCatalogService())->visibleChannelCodes();
        $this->assertContains('STABLE', $codes);
        $this->assertContains('BETA', $codes);
        $this->assertNotContains('INTERNAL', $codes);
        $this->assertSame(count($codes), count(array_unique($codes)));
    }
}
