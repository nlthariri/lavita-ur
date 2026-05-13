import { z } from "zod";

const envSchema = z.object({
  DATABASE_URL: z.string().min(1, "DATABASE_URL ontbreekt"),
  SMTP_HOST: z.string().min(1, "SMTP_HOST ontbreekt"),
  SMTP_PORT: z.coerce.number().int().refine((value) => value === 465 || value === 587, {
    message: "SMTP_PORT moet 465 (TLS) of 587 (STARTTLS) zijn",
  }),
  SMTP_USER: z.string().min(1, "SMTP_USER ontbreekt"),
  SMTP_PASSWORD: z.string().min(1, "SMTP_PASSWORD ontbreekt"),
  SMTP_FROM: z.string().min(1, "SMTP_FROM ontbreekt"),
  APP_BASE_URL: z.string().url(),
  AUTH_SESSION_SECRET: z.string().min(32, "AUTH_SESSION_SECRET moet minimaal 32 tekens zijn"),
  REDIS_URL: z.string().url().optional(),
});

type Env = z.infer<typeof envSchema>;

let cachedEnv: Env | null = null;

export function getEnv(): Env {
  if (cachedEnv) {
    return cachedEnv;
  }

  cachedEnv = envSchema.parse({
    DATABASE_URL: process.env.DATABASE_URL,
    SMTP_HOST: process.env.SMTP_HOST,
    SMTP_PORT: process.env.SMTP_PORT,
    SMTP_USER: process.env.SMTP_USER,
    SMTP_PASSWORD: process.env.SMTP_PASSWORD,
    SMTP_FROM: process.env.SMTP_FROM,
    APP_BASE_URL: process.env.APP_BASE_URL,
    AUTH_SESSION_SECRET: process.env.AUTH_SESSION_SECRET,
    REDIS_URL: process.env.REDIS_URL,
  });

  return cachedEnv;
}
