import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';
import { BugReportPage } from '@/pages/public/BugReportPage';
import { publicApi } from '@/services/public';
import { ApiError } from '@/services/api';

function renderPage(url = '/report-a-problem') {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[url]}>
        <BugReportPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('BugReportPage (public report form)', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    vi.spyOn(publicApi, 'apps').mockResolvedValue({
      data: [
        { slug: 'desktop', name: 'WorkSuite Desktop' },
        { slug: 'server', name: 'WorkSuite Server' },
      ],
    } as any);
  });

  it('requires a summary before submitting', async () => {
    const submit = vi.spyOn(publicApi, 'submitBugReport');
    const user = userEvent.setup();
    renderPage();
    await user.click(await screen.findByRole('button', { name: 'Send report' }));
    expect(await screen.findByText('Give the problem a short summary')).toBeInTheDocument();
    expect(submit).not.toHaveBeenCalled();
  });

  it('submits the report and shows the reference with a status link', async () => {
    const submit = vi.spyOn(publicApi, 'submitBugReport').mockResolvedValue({ reference: 'BR-20001', status_url: '/api/v1/bugtrace/reports/BR-20001/status', status_token: 'tok123' });
    const user = userEvent.setup();
    renderPage('/report-a-problem?app=server&version=3.4.0');
    await user.type(await screen.findByLabelText(/Summary/), 'Installer says the file is damaged');
    await user.type(screen.getByLabelText('Steps to reproduce'), '1. Open installer{enter}- Click next');
    await user.click(screen.getByRole('button', { name: 'Send report' }));
    expect(await screen.findByText('BR-20001')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Track the status of this report' })).toHaveAttribute('href', '/report-a-problem/status?ref=BR-20001&token=tok123');
    const body = submit.mock.calls[0][0];
    expect(body).toMatchObject({ app: 'server', release: '3.4.0', title: 'Installer says the file is damaged', steps: ['Open installer', 'Click next'], category: 'BUG', severity: 'ANNOYING', contact: { consent: false }, website: '' });
    expect(body.captcha_token).not.toBe('');
    expect(body.client_report_id).toMatch(/^[A-Za-z0-9_-]{8,64}$/);
  });

  it('asks for an email when updates are requested and shows server field errors', async () => {
    vi.spyOn(publicApi, 'submitBugReport').mockRejectedValue(new ApiError(422, 'VALIDATION_FAILED', 'Check the highlighted fields', { 'contact.email': 'Invalid e-mail address' }));
    const user = userEvent.setup();
    renderPage();
    await user.type(await screen.findByLabelText(/Summary/), 'Crash on start');
    await user.click(screen.getByLabelText('Email me about updates to this report'));
    await user.click(screen.getByRole('button', { name: 'Send report' }));
    expect(await screen.findByText('Enter a valid email address')).toBeInTheDocument();
    await user.type(screen.getByLabelText(/Email address/), 'a@b.co');
    await user.click(screen.getByRole('button', { name: 'Send report' }));
    expect(await screen.findByText('Invalid e-mail address')).toBeInTheDocument();
  });
});
