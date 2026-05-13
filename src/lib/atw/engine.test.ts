import { evaluateAtwSignals } from "@/lib/atw/engine";

const basePolicy = {
  dailyMaxMinutes: 12 * 60,
  weeklyMaxMinutes: 60 * 60,
  weeklyWarningMinutes: 48 * 60,
  sixteenWeekAverageMaxMinutes: 48 * 60,
  minimumRestMinutes: 11 * 60,
};

describe("evaluateAtwSignals", () => {
  it("geeft DAILY_LIMIT en WEEKLY_LIMIT bij overschrijding", () => {
    const signals = evaluateAtwSignals({
      proposedShift: {
        startAt: new Date("2026-05-15T06:00:00.000Z"),
        endAt: new Date("2026-05-15T20:00:00.000Z"),
        netMinutes: 13 * 60,
      },
      existingShifts: [
        {
          id: "w1",
          startAt: new Date("2026-05-11T06:00:00.000Z"),
          endAt: new Date("2026-05-11T18:00:00.000Z"),
          netMinutes: 12 * 60,
        },
        {
          id: "w2",
          startAt: new Date("2026-05-12T06:00:00.000Z"),
          endAt: new Date("2026-05-12T18:00:00.000Z"),
          netMinutes: 12 * 60,
        },
        {
          id: "w3",
          startAt: new Date("2026-05-13T06:00:00.000Z"),
          endAt: new Date("2026-05-13T18:00:00.000Z"),
          netMinutes: 12 * 60,
        },
        {
          id: "w4",
          startAt: new Date("2026-05-14T06:00:00.000Z"),
          endAt: new Date("2026-05-14T18:00:00.000Z"),
          netMinutes: 12 * 60,
        },
      ],
      policy: basePolicy,
    });

    expect(signals.some((signal) => signal.type === "DAILY_LIMIT")).toBe(true);
    expect(signals.some((signal) => signal.type === "WEEKLY_LIMIT")).toBe(true);
  });

  it("geeft REST_PERIOD bij onvoldoende rust", () => {
    const signals = evaluateAtwSignals({
      proposedShift: {
        startAt: new Date("2026-05-12T06:00:00.000Z"),
        endAt: new Date("2026-05-12T14:00:00.000Z"),
        netMinutes: 8 * 60,
      },
      existingShifts: [
        {
          id: "prev",
          startAt: new Date("2026-05-11T12:00:00.000Z"),
          endAt: new Date("2026-05-11T23:00:00.000Z"),
          netMinutes: 10 * 60,
        },
      ],
      policy: basePolicy,
    });

    const restSignal = signals.find((signal) => signal.type === "REST_PERIOD");
    expect(restSignal).toBeDefined();
    expect(restSignal?.severity).toBe("critical");
  });

  it("geeft WEEKLY_WARNING zonder WEEKLY_LIMIT rond waarschuwingsgrens", () => {
    const signals = evaluateAtwSignals({
      proposedShift: {
        startAt: new Date("2026-05-15T06:00:00.000Z"),
        endAt: new Date("2026-05-15T15:00:00.000Z"),
        netMinutes: 9 * 60,
      },
      existingShifts: [
        {
          id: "w1",
          startAt: new Date("2026-05-11T06:00:00.000Z"),
          endAt: new Date("2026-05-11T16:00:00.000Z"),
          netMinutes: 10 * 60,
        },
        {
          id: "w2",
          startAt: new Date("2026-05-12T06:00:00.000Z"),
          endAt: new Date("2026-05-12T16:00:00.000Z"),
          netMinutes: 10 * 60,
        },
        {
          id: "w3",
          startAt: new Date("2026-05-13T06:00:00.000Z"),
          endAt: new Date("2026-05-13T16:00:00.000Z"),
          netMinutes: 10 * 60,
        },
        {
          id: "w4",
          startAt: new Date("2026-05-14T06:00:00.000Z"),
          endAt: new Date("2026-05-14T16:00:00.000Z"),
          netMinutes: 10 * 60,
        },
      ],
      policy: basePolicy,
    });

    expect(signals.some((signal) => signal.type === "WEEKLY_WARNING")).toBe(true);
    expect(signals.some((signal) => signal.type === "WEEKLY_LIMIT")).toBe(false);
  });
});
