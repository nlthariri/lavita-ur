module.exports = {
  apps: [
    {
      name: "lavita-ur",
      script: "npm",
      args: "start",
      cwd: ".",
      instances: Number(process.env.WEB_CONCURRENCY || "1"),
      exec_mode: "cluster",
      max_memory_restart: "512M",
      autorestart: true,
      watch: false,
      env_production: {
        NODE_ENV: "production",
        PORT: process.env.PORT || "3000",
      },
    },
    {
      name: "lavita-retention-job",
      script: "npm",
      args: "run retention:pseudonymize",
      cwd: ".",
      instances: 1,
      exec_mode: "fork",
      autorestart: false,
      watch: false,
      cron_restart: "0 2 * * 0",
      env_production: {
        NODE_ENV: "production",
      },
    },
    {
      name: "lavita-email-outbox-job",
      script: "npm",
      args: "run email:outbox:process",
      cwd: ".",
      instances: 1,
      exec_mode: "fork",
      autorestart: false,
      watch: false,
      cron_restart: "*/2 * * * *",
      env_production: {
        NODE_ENV: "production",
      },
    },
  ],
};
