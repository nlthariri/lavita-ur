import bcrypt from "bcryptjs";
import { PrismaClient, UserRole } from "@prisma/client";

const prisma = new PrismaClient();

async function main() {
  const organizationName = process.env.BOOTSTRAP_ORGANIZATION_NAME;
  const ownerName = process.env.BOOTSTRAP_OWNER_NAME;
  const ownerEmail = process.env.BOOTSTRAP_OWNER_EMAIL?.toLowerCase().trim();
  const ownerPassword = process.env.BOOTSTRAP_OWNER_PASSWORD;

  if (!organizationName || !ownerName || !ownerEmail || !ownerPassword) {
    throw new Error(
      "Ontbrekende bootstrapvariabelen. Vereist: BOOTSTRAP_ORGANIZATION_NAME, BOOTSTRAP_OWNER_NAME, BOOTSTRAP_OWNER_EMAIL, BOOTSTRAP_OWNER_PASSWORD.",
    );
  }

  if (ownerPassword.length < 12) {
    throw new Error("BOOTSTRAP_OWNER_PASSWORD moet minimaal 12 tekens bevatten.");
  }

  const existingOwner = await prisma.user.findUnique({
    where: { email: ownerEmail },
    select: { id: true },
  });

  if (existingOwner) {
    console.log("Eigenaar bestaat al, bootstrap overgeslagen.");
    return;
  }

  let organization = await prisma.organization.findFirst({
    where: { name: organizationName },
    select: { id: true },
  });

  if (!organization) {
    organization = await prisma.organization.create({
      data: {
        name: organizationName,
      },
      select: { id: true },
    });
  }

  const passwordHash = await bcrypt.hash(ownerPassword, 12);

  const owner = await prisma.user.create({
    data: {
      organizationId: organization.id,
      fullName: ownerName,
      email: ownerEmail,
      role: UserRole.OWNER,
      passwordHash,
      isActive: true,
      mfaEnabled: false,
    },
    select: {
      id: true,
      email: true,
      role: true,
    },
  });

  console.log("Bootstrap gereed. Eerste eigenaar aangemaakt:");
  console.log(owner);
  console.log("Volgende stap: log in en activeer MFA via /dashboard/mfa");
}

main()
  .catch((error) => {
    console.error(error instanceof Error ? error.message : String(error));
    process.exitCode = 1;
  })
  .finally(async () => {
    await prisma.$disconnect();
  });
