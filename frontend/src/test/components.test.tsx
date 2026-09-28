import { describe, expect, it } from 'vitest';
import { useState } from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ReleaseChecklist, checklistBlockers } from '@/components/ReleaseChecklist';
import { TypeToConfirm, isConfirmed } from '@/components/TypeToConfirm';
import { OtpInput } from '@/components/OtpInput';
import type { Checklist } from '@/types/api';

const checklist: Checklist = {
  passed: false,
  items: [
    { key: 'version', label: 'Version entered · semver valid', status: 'pass', blocking: true, detail: null },
    { key: 'pkg_linux', label: 'Linux packages uploaded', status: 'fail', blocking: true, detail: '1 missing' },
    { key: 'sha256', label: 'SHA-256 calculated (8/9)', status: 'pending', blocking: true, detail: null },
    { key: 'approvals', label: 'QA approval + Release Manager approval', status: 'pending', blocking: false, detail: null },
  ],
};

describe('ReleaseChecklist', () => {
  it('renders every item with an accessible status and counts blocking items', () => {
    render(<ReleaseChecklist checklist={checklist} />);
    const list = screen.getByRole('list', { name: 'Release checklist' });
    expect(list.querySelectorAll('li')).toHaveLength(4);
    expect(screen.getByText('Linux packages uploaded').closest('li')).toHaveAttribute('data-status', 'fail');
    expect(screen.getByText('Failed:')).toBeInTheDocument();
    expect(screen.getByText('1 missing')).toBeInTheDocument();
    expect(screen.getByText('(advisory)')).toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent('2 blocking items — publish is blocked until all pass.');
  });
  it('reports "All checks passed" when nothing blocks', () => {
    render(<ReleaseChecklist checklist={{ passed: true, items: [checklist.items[0], checklist.items[3]] }} />);
    expect(screen.getByRole('status')).toHaveTextContent('All checks passed.');
  });
  it('checklistBlockers ignores advisory and passing items', () => {
    expect(checklistBlockers(checklist).map((i) => i.key)).toEqual(['pkg_linux', 'sha256']);
    expect(checklistBlockers(null)).toEqual([]);
  });
});

function Harness({ expected }: { expected: string }) {
  const [v, setV] = useState('');
  return (
    <>
      <TypeToConfirm expected={expected} value={v} onChange={setV} />
      <button disabled={!isConfirmed(expected, v)}>Withdraw Release</button>
    </>
  );
}

describe('TypeToConfirm', () => {
  it('only enables the destructive action on an exact match', async () => {
    const user = userEvent.setup();
    render(<Harness expected="2.6.4" />);
    const input = screen.getByLabelText(/Type-to-confirm/);
    const button = screen.getByRole('button', { name: 'Withdraw Release' });
    expect(button).toBeDisabled();
    await user.type(input, '2.6.');
    expect(button).toBeDisabled();
    expect(input).toHaveAttribute('aria-invalid', 'true');
    await user.type(input, '4');
    expect(button).toBeEnabled();
    expect(screen.getByText('Confirmed')).toBeInTheDocument();
    await user.type(input, '1');
    expect(button).toBeDisabled();
  });
  it('isConfirmed trims whitespace but is otherwise exact', () => {
    expect(isConfirmed('2.7.0', ' 2.7.0 ')).toBe(true);
    expect(isConfirmed('2.7.0', '2.7')).toBe(false);
    expect(isConfirmed('', '')).toBe(false);
  });
});

describe('OtpInput', () => {
  it('fills all digits from a paste and exposes labelled inputs', async () => {
    const user = userEvent.setup();
    let value = '';
    function H() {
      const [v, setV] = useState('');
      value = v;
      return <OtpInput value={v} onChange={setV} />;
    }
    render(<H />);
    expect(screen.getAllByRole('textbox')).toHaveLength(6);
    await user.click(screen.getByLabelText('Digit 1 of 6'));
    await user.paste('481516');
    expect(value).toBe('481516');
  });
});
