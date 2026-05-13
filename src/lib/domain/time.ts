const TIME_24H_PATTERN = /^([01]\d|2[0-3]):([0-5]\d)$/;

export function parseTimeOnDate(date: Date, time: string): Date {
  const match = TIME_24H_PATTERN.exec(time);
  if (!match) {
    throw new Error("Ongeldig tijdformaat. Gebruik HH:mm.");
  }

  const [, hourValue, minuteValue] = match;
  const hour = Number(hourValue);
  const minute = Number(minuteValue);

  const result = new Date(date);
  result.setHours(hour, minute, 0, 0);
  return result;
}

export function calculateNetMinutes(startAt: Date, endAt: Date, pauseMinutes: number): number {
  const startMs = startAt.getTime();
  const endMs = endAt.getTime();

  if (endMs <= startMs) {
    throw new Error("Eindtijd moet na begintijd liggen.");
  }

  if (pauseMinutes < 0) {
    throw new Error("Pauze mag niet negatief zijn.");
  }

  const grossMinutes = Math.floor((endMs - startMs) / 60000);
  const netMinutes = grossMinutes - pauseMinutes;

  if (netMinutes <= 0) {
    throw new Error("Netto werktijd moet groter zijn dan 0 minuten.");
  }

  return netMinutes;
}
