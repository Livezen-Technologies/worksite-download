import type { ReactNode } from 'react';
import { Link } from 'react-router';
import { ApiError } from '@/services/api';
import { ErrorState, Spinner } from '@/components/ui';
import { cx } from '@/core/format';

export function Container({ children, className }: { children: ReactNode; className?: string }) {
  return <div className={cx('mx-auto max-w-6xl px-4 sm:px-6', className)}>{children}</div>;
}

export function QueryState({ error, isLoading, refetch }: { error: unknown; isLoading: boolean; refetch?: () => void }) {
  if (isLoading) return <Spinner />;
  if (error instanceof ApiError && (error.status === 404 || error.code === 'RELEASE_NOT_AVAILABLE')) return <NotFoundBlock message={error.message} />;
  if (error) return <ErrorState error={error} retry={refetch} />;
  return null;
}

export function NotFoundBlock({ message }: { message?: string }) {
  return (
    <div className="card mx-auto my-10 max-w-lg p-8 text-center">
      <h1 className="text-[20px] font-bold">Not found</h1>
      <p className="mt-2 text-[13px] text-subtle">{message ?? 'This page or version is not available.'}</p>
      <Link to="/" className="link mt-4 inline-block text-[13px]">
        Go to WorkSuite Downloads
      </Link>
    </div>
  );
}

export function Breadcrumb({ items }: { items: { label: string; to?: string }[] }) {
  return (
    <nav aria-label="Breadcrumb">
      <ol className="flex flex-wrap items-center gap-1 text-[12px] text-subtle">
        {items.map((it, i) => (
          <li key={i} className="flex items-center gap-1">
            {i > 0 && <span aria-hidden>/</span>}
            {it.to ? (
              <Link to={it.to} className="link">
                {it.label}
              </Link>
            ) : (
              <span aria-current="page">{it.label}</span>
            )}
          </li>
        ))}
      </ol>
    </nav>
  );
}

export function Pager({ basePath, page, total, pageSize }: { basePath: string; page: number; total: number; pageSize: number }) {
  const pages = Math.max(1, Math.ceil(total / pageSize));
  if (pages <= 1) return null;
  return (
    <nav aria-label="Pagination" className="mt-6 flex items-center justify-between text-[13px]">
      {page > 1 ? (
        <Link className="link" to={`${basePath}?page=${page - 1}`}>
          ← Newer
        </Link>
      ) : (
        <span />
      )}
      <span className="text-subtle">
        Page {page} of {pages}
      </span>
      {page < pages ? (
        <Link className="link" to={`${basePath}?page=${page + 1}`}>
          Older →
        </Link>
      ) : (
        <span />
      )}
    </nav>
  );
}
