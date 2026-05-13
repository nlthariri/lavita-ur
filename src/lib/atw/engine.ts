import { addDays, startOfWeek, subWeeks } from "date-fns";
import { fromZonedTime, toZonedTime } from "date-fns-tz";

export type AtwPolicy = {
  dailyMaxMinutes: number;
  weeklyMaxMinutes: number;
  weeklyWarningMinutes: number;
  sixteenWeekAverageMaxMinutes: number;
  minimumRestMinutes: number;
};

export type ExistingShift = {
  id: string;
  startAt: Date;
  endAt: Date;
  netMinutes: number;
};

export type AtwSignalType =
  | "DAILY_LIMIT"
  | "WEEKLY_WARNING"
  | "WEEKLY_LIMIT"
  | "SIXTEEN_WEEK_AVERAGE"
  | "REST_PERIOD";

export type AtwSignal = {
  type: AtwSignalType;
  severity: "warning" | "critical";
  message: string;
  thresholdMinutes: number;
  currentMinutes: number;
};

export type EvaluateAtwInput = {
  proposedShift: {
    startAt: Date;
    endAt: Date;
    netMinutes: number;
  };
  existingShifts: ExistingShift[];
  policy: AtwPolicy;
  timezone?: string;
};

function sumMinutes(shifts: ExistingShift[]): number {
  return shifts.reduce((sum, shift) => sum + shift.netMinutes, 0);
}

function overlapsWeek(targetDate: Date, shiftDate: Date, timezone: string): boolean {
  const zonedTarget = toZonedTime(targetDate, timezone);
  const weekStartZoned = startOfWeek(zonedTarget, { weekStartsOn: 1 });
  const weekEndZoned = addDays(weekStartZoned, 7);
  const weekStartUtc = fromZonedTime(weekStartZoned, timezone);
  const weekEndUtc = fromZonedTime(weekEndZoned, timezone);

  return shiftDate >= weekStartUtc && shiftDate < weekEndUtc;
}

function calculateRestMinutes(previousEndAt: Date, nextStartAt: Date): number {
  return Math.floor((nextStartAt.getTime() - previousEndAt.getTime()) / 60000);
}

export function evaluateAtwSignals(input: EvaluateAtwInput): AtwSignal[] {
  const { policy, existingShifts, proposedShift, timezone = "Europe/Amsterdam" } = input;
  const signals: AtwSignal[] = [];

  if (proposedShift.netMinutes >= policy.dailyMaxMinutes) {
    signals.push({
      type: "DAILY_LIMIT",
      severity: "critical",
      message: "Daglimiet bereikt of overschreden (12 uur).",
      thresholdMinutes: policy.dailyMaxMinutes,
      currentMinutes: proposedShift.netMinutes,
    });
  }

  const weeklyMinutes =
    sumMinutes(existingShifts.filter((shift) => overlapsWeek(proposedShift.startAt, shift.startAt, timezone))) +
    proposedShift.netMinutes;

  if (weeklyMinutes >= policy.weeklyWarningMinutes && weeklyMinutes < policy.weeklyMaxMinutes) {
    signals.push({
      type: "WEEKLY_WARNING",
      severity: "warning",
      message: "Naderende ATW-weeklimiet (48 uur of meer in huidige week).",
      thresholdMinutes: policy.weeklyWarningMinutes,
      currentMinutes: weeklyMinutes,
    });
  }

  if (weeklyMinutes >= policy.weeklyMaxMinutes) {
    signals.push({
      type: "WEEKLY_LIMIT",
      severity: "critical",
      message: "ATW-weeklimiet overschreden (60 uur).",
      thresholdMinutes: policy.weeklyMaxMinutes,
      currentMinutes: weeklyMinutes,
    });
  }

  const lookbackStart = subWeeks(proposedShift.startAt, 16);
  const shiftsInPeriod = existingShifts.filter((shift) => shift.startAt >= lookbackStart && shift.endAt <= proposedShift.endAt);
  const totalMinutes16Weeks = sumMinutes(shiftsInPeriod) + proposedShift.netMinutes;
  const averageWeeklyMinutes = Math.floor(totalMinutes16Weeks / 16);

  if (averageWeeklyMinutes >= policy.sixteenWeekAverageMaxMinutes) {
    signals.push({
      type: "SIXTEEN_WEEK_AVERAGE",
      severity: "critical",
      message: "Gemiddelde over 16 weken overschrijdt 48 uur per week.",
      thresholdMinutes: policy.sixteenWeekAverageMaxMinutes,
      currentMinutes: averageWeeklyMinutes,
    });
  }

  const nearestPrevious = [...existingShifts]
    .filter((shift) => shift.endAt <= proposedShift.startAt)
    .sort((a, b) => b.endAt.getTime() - a.endAt.getTime())[0];

  if (nearestPrevious) {
    const restMinutes = calculateRestMinutes(nearestPrevious.endAt, proposedShift.startAt);
    if (restMinutes < policy.minimumRestMinutes) {
      signals.push({
        type: "REST_PERIOD",
        severity: "critical",
        message: "Rusttijd tussen diensten is minder dan 11 uur.",
        thresholdMinutes: policy.minimumRestMinutes,
        currentMinutes: restMinutes,
      });
    }
  }

  return signals;
}
