import { useEffect, useState } from 'react';
import { detectFromUserAgent, detectPlatform, NONE, type DetectedPlatform } from '@/core/platform';

/**
 * Detected platform; starts as NONE so server-rendered HTML and the first client render match (hydration),
 * then refines from the UA string and client hints. Never blocks rendering.
 */
export function usePlatform(): DetectedPlatform {
  const [detected, setDetected] = useState<DetectedPlatform>(NONE);
  useEffect(() => {
    let alive = true;
    setDetected(detectFromUserAgent(navigator));
    detectPlatform().then((d) => alive && setDetected(d));
    return () => {
      alive = false;
    };
  }, []);
  return detected;
}
