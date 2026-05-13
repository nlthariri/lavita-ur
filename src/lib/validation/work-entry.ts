import { WorkEntryType } from "@prisma/client";
import { z } from "zod";

const HHMM_PATTERN = /^([01]\d|2[0-3]):([0-5]\d)$/;

export const workEntryInputSchema = z.object({
  employeeId: z.string().cuid(),
  projectId: z.string().cuid().optional(),
  entryDate: z.coerce.date(),
  startTime: z.string().regex(HHMM_PATTERN, "Gebruik HH:mm voor begintijd."),
  endTime: z.string().regex(HHMM_PATTERN, "Gebruik HH:mm voor eindtijd."),
  pauseMinutes: z.number().int().min(0).max(240),
  type: z.nativeEnum(WorkEntryType).default(WorkEntryType.WORK),
  note: z.string().trim().max(500).optional(),
});

export type WorkEntryInput = z.infer<typeof workEntryInputSchema>;
