# Daymark frontend

## Run through Laravel

Build the frontend so Laravel can serve its files from `backend/public/ui`:

```powershell
Set-Location frontend
npm install
npm run build
Set-Location ..\backend
php artisan serve
```

Open `http://127.0.0.1:8000`. The login screen is the first view for signed-out users.

For frontend development with Vite hot reload, run `npm run dev` from this directory
and `php artisan serve` from `backend` in separate terminals.
