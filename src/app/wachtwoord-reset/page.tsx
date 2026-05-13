import { PasswordResetForm } from "@/components/auth/password-reset-form";

type WachtwoordResetPageProps = {
  searchParams: Promise<{ token?: string }>;
};

export default async function WachtwoordResetPage({ searchParams }: WachtwoordResetPageProps) {
  const params = await searchParams;
  const token = params.token ?? "";

  return (
    <main className="mx-auto flex min-h-screen w-full max-w-md items-center px-5 py-10">
      <PasswordResetForm token={token} />
    </main>
  );
}
