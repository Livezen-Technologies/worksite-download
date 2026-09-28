import { cx } from '@/core/format';

export function LogoMark({ className }: { className?: string }) {
  return (
    <span aria-hidden className={cx('grid size-6 place-items-center rounded-md bg-brand text-[12px] font-extrabold text-white', className)}>
      W
    </span>
  );
}
