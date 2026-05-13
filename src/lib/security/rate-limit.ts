import { createClient } from "redis";

import { getEnv } from "@/lib/env";
import { logWarn } from "@/lib/observability/log";

type Bucket = {
  count: number;
  resetAt: number;
};

type RedisClient = ReturnType<typeof createClient>;

const buckets = new Map<string, Bucket>();
let cleanupStarted = false;
let redisClient: RedisClient | null = null;
let redisInitAttempted = false;
let redisDisabled = false;

export type RateLimitResult = {
  allowed: boolean;
  fallbackUsed: boolean;
  degraded: boolean;
};

function markRedisDegraded(reason: string): void {
  if (!redisDisabled) {
    logWarn("rate_limit.degraded", "Rate limiting gebruikt memory fallback door Redis-probleem.", {
      reason,
    });
  }

  redisDisabled = true;
}

async function getRedisClient(): Promise<RedisClient | null> {
  if (redisDisabled) {
    return null;
  }

  if (redisClient) {
    return redisClient;
  }

  if (redisInitAttempted) {
    return null;
  }

  redisInitAttempted = true;

  try {
    const env = getEnv();
    if (!env.REDIS_URL) {
      return null;
    }

    const client = createClient({
      url: env.REDIS_URL,
      socket: {
        connectTimeout: 3000,
      },
    });

    client.on("error", () => {
      markRedisDegraded("redis_client_error");
    });

    await client.connect();
    redisClient = client;
    return redisClient;
  } catch {
    markRedisDegraded("redis_connect_error");
    return null;
  }
}

function startCleanup(): void {
  if (cleanupStarted) {
    return;
  }

  cleanupStarted = true;

  setInterval(() => {
    const now = Date.now();
    for (const [key, bucket] of buckets.entries()) {
      if (bucket.resetAt <= now) {
        buckets.delete(key);
      }
    }
  }, 60 * 60 * 1000).unref();
}

function checkRateLimitMemory(key: string, maxRequests: number, windowMs: number): boolean {
  startCleanup();

  const now = Date.now();
  const current = buckets.get(key);

  if (!current || current.resetAt <= now) {
    buckets.set(key, { count: 1, resetAt: now + windowMs });
    return true;
  }

  if (current.count >= maxRequests) {
    return false;
  }

  current.count += 1;
  buckets.set(key, current);
  return true;
}

export async function checkRateLimitDetailed(
  key: string,
  maxRequests: number,
  windowMs: number,
): Promise<RateLimitResult> {
  const redis = await getRedisClient();
  if (!redis) {
    return {
      allowed: checkRateLimitMemory(key, maxRequests, windowMs),
      fallbackUsed: true,
      degraded: true,
    };
  }

  try {
    const namespacedKey = `rate-limit:${key}`;
    const count = await redis.incr(namespacedKey);
    if (count === 1) {
      await redis.pExpire(namespacedKey, windowMs);
    }

    return {
      allowed: count <= maxRequests,
      fallbackUsed: false,
      degraded: false,
    };
  } catch {
    markRedisDegraded("redis_runtime_error");
    return {
      allowed: checkRateLimitMemory(key, maxRequests, windowMs),
      fallbackUsed: true,
      degraded: true,
    };
  }
}

export async function checkRateLimit(key: string, maxRequests: number, windowMs: number): Promise<boolean> {
  const result = await checkRateLimitDetailed(key, maxRequests, windowMs);
  return result.allowed;
}
