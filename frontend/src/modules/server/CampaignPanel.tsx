import { useState } from 'react';
import { adminApi } from '@/services/admin';
import type { AdminReleaseDetail, Campaign, CampaignBody, CampaignChannel, DeploymentMode, Edition, ReleaseType } from '@/types/api';
import { Button, Card, CardHeader, Checkbox, KeyValue, Select, TextField } from '@/components/ui';
import { Badge } from '@/components/ui';
import { useReleaseMutation } from '@/modules/releases/hooks';
import { formatDateTime, titleCase } from '@/core/format';

const CH_LABEL: Record<CampaignChannel, string> = { webhook: 'Webhook', in_app: 'in-app', email: 'email to system admins', whatsapp: 'WhatsApp' };

export function campaignSummary(c: Campaign | CampaignBody) {
  const modes = c.audience.modes.map((m) => titleCase(m.replace('_', ' '))).join(' / ');
  return {
    audience: `${modes || 'All'} installations on ${c.audience.version_range || 'any version'}${'eligible_count' in c ? ` · ${c.eligible_count}` : ''}`,
    channels: c.channels.map((x) => CH_LABEL[x]).join(' → ') + (c.channels.includes('email') ? '; email to license contact' : ''),
    fleet: c.fleet_window ? `Auto-update in maintenance window ${c.fleet_window}` : 'No managed window',
    urgency: `${titleCase(c.urgency)} · ${c.deadline ? `mandatory after ${formatDateTime(c.deadline)}` : 'mandatory after — none'}`,
    rollout: `Notify ${c.rollout_pct}% → 100% after 48 h healthy`,
  };
}

/** W1 admin-notification campaign panel (§17.6): audience, channels, managed fleet window, urgency, rollout. */
export function CampaignPanel({ release, canEdit }: { release: AdminReleaseDetail; canEdit: boolean }) {
  const c = release.campaign;
  const [editing, setEditing] = useState(false);
  const [f, setF] = useState<CampaignBody | null>(c ? { audience: c.audience, urgency: c.urgency, deadline: c.deadline, rollout_pct: c.rollout_pct, channels: c.channels, fleet_window: c.fleet_window, fleet_stages: c.fleet_stages } : null);
  const save = useReleaseMutation(release.id, (body: CampaignBody) => adminApi.saveCampaign(release.id, body), 'Notification campaign saved');
  if (!c || !f) return null;
  const s = campaignSummary(c);
  const toggle = <T,>(list: T[], v: T) => (list.includes(v) ? list.filter((x) => x !== v) : [...list, v]);
  return (
    <Card>
      <CardHeader
        title="Admin notification on publish"
        actions={
          <>
            <Badge tone={c.status === 'SENT' ? 'green' : 'grey'}>{c.status}</Badge>
            {canEdit && !editing && (
              <Button size="sm" onClick={() => setEditing(true)}>
                Edit
              </Button>
            )}
          </>
        }
      />
      <div className="px-4 pb-4">
        {!editing ? (
          <KeyValue
            rows={[
              ['Audience', s.audience],
              ['Channels', s.channels],
              ['Managed (hosted) fleet', s.fleet],
              ['Urgency', s.urgency],
              ['Rollout', s.rollout],
              ...(c.sent_count ? ([['Sent', `${c.sent_count} of ${c.eligible_count}`]] as [string, string][]) : []),
            ]}
          />
        ) : (
          <form
            className="grid gap-3 text-[13px]"
            onSubmit={(e) => {
              e.preventDefault();
              save.mutate(f, { onSuccess: () => setEditing(false) });
            }}
          >
            <fieldset>
              <legend className="mb-1 text-[12px] font-semibold">Editions</legend>
              <div className="flex flex-wrap gap-3">
                {(['FREE', 'BUSINESS', 'ENTERPRISE'] as Edition[]).map((ed) => (
                  <Checkbox key={ed} label={titleCase(ed)} checked={f.audience.editions.includes(ed)} onChange={() => setF({ ...f, audience: { ...f.audience, editions: toggle(f.audience.editions, ed) } })} />
                ))}
              </div>
            </fieldset>
            <fieldset>
              <legend className="mb-1 text-[12px] font-semibold">Deployment modes</legend>
              <div className="flex flex-wrap gap-3">
                {(['MANAGED', 'SELF_HOSTED', 'AIR_GAPPED'] as DeploymentMode[]).map((m) => (
                  <Checkbox key={m} label={titleCase(m.replace('_', ' '))} checked={f.audience.modes.includes(m)} onChange={() => setF({ ...f, audience: { ...f.audience, modes: toggle(f.audience.modes, m) } })} />
                ))}
              </div>
            </fieldset>
            <TextField label="Version range" mono value={f.audience.version_range} onChange={(e) => setF({ ...f, audience: { ...f.audience, version_range: e.target.value } })} />
            <fieldset>
              <legend className="mb-1 text-[12px] font-semibold">Channels</legend>
              <div className="flex flex-wrap gap-3">
                {(['webhook', 'in_app', 'email', 'whatsapp'] as CampaignChannel[]).map((ch) => (
                  <Checkbox key={ch} label={CH_LABEL[ch]} checked={f.channels.includes(ch)} onChange={() => setF({ ...f, channels: toggle(f.channels, ch) })} />
                ))}
              </div>
            </fieldset>
            <div className="grid gap-3 sm:grid-cols-2">
              <Select label="Urgency" value={f.urgency} onChange={(e) => setF({ ...f, urgency: e.target.value as ReleaseType })} options={['OPTIONAL', 'RECOMMENDED', 'MANDATORY', 'CRITICAL'].map((v) => ({ value: v, label: titleCase(v) }))} />
              <TextField label="Deadline (mandatory)" type="datetime-local" value={f.deadline?.slice(0, 16) ?? ''} onChange={(e) => setF({ ...f, deadline: e.target.value ? new Date(e.target.value).toISOString() : null })} />
              <TextField label="Initial notify %" type="number" min={1} max={100} value={f.rollout_pct} onChange={(e) => setF({ ...f, rollout_pct: Number(e.target.value) })} />
              <TextField label="Managed fleet window" value={f.fleet_window ?? ''} onChange={(e) => setF({ ...f, fleet_window: e.target.value || null })} placeholder="Sun 02:00–04:00 LKT" />
            </div>
            <div className="flex gap-2">
              <Button type="submit" variant="primary" size="sm" loading={save.isPending}>
                Save campaign
              </Button>
              <Button size="sm" onClick={() => setEditing(false)}>
                Cancel
              </Button>
            </div>
          </form>
        )}
      </div>
    </Card>
  );
}
