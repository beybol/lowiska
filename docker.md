# Docker Setup for Laravel Application

## Overview

This Laravel application includes Docker containerization with separate environments for development and production. The setup includes support for PHP 8.3, Apache, Node.js/npm, Composer, and separate containers for queue workers and schedulers.

## Architecture

- **app**: Main Laravel application with Apache web server (port 8000)
- **queue**: Queue worker container for background job processing (emails, exports, etc.)
- **scheduler**: Cron scheduler container for running scheduled tasks (cleanup, reports, etc.)
- **External Database**: Connects to external MySQL database (local XAMPP, remote server, cloud service)

**Important**: All containers share the same database connection and require database tables to function properly. Queue and scheduler containers will fail to start if database tables don't exist, so migrations must be run before full startup.

## Database Configuration Options

This application is configured to use an **external MySQL database** instead of a containerized one. This allows for flexible deployment scenarios:

### Option 1: Local MySQL (XAMPP/WAMP/Local Installation)

For local MySQL database (XAMPP, WAMP, or directly installed MySQL):

```env
DB_HOST=host.docker.internal  # Windows/Mac
# or
DB_HOST=172.17.0.1           # Linux Docker default gateway
DB_PORT=3306
DB_DATABASE=łowiska
DB_USERNAME=docker           # Recommended: create dedicated user
# or
DB_USERNAME=root             # Alternative: use existing root user
DB_PASSWORD=your_password    # Set when creating user or use existing password
```

**Note**: 
- Use `host.docker.internal` on Windows/Mac, or Docker gateway IP (`172.17.0.1`) on Linux
- For security, create a dedicated `docker` user instead of using `root`
- The password must match what you set when creating the database user

### Option 2: Remote/Cloud MySQL Database

For remote MySQL databases (VPS, dedicated servers, cloud services like AWS RDS, Google Cloud SQL, Azure Database):

```env
DB_HOST=your-mysql-server.com          # Remote server IP/hostname
# or
DB_HOST=your-cloud-instance.region.rds.amazonaws.com  # Cloud service endpoint
DB_PORT=3306
DB_DATABASE=łowiska
DB_USERNAME=your_username              # Server-specific username
DB_PASSWORD=your_password              # Server-specific password
```

## Database Requirements

Before starting the application, ensure your MySQL database is properly configured:

1. **Database exists**: Create a database named `łowiska` (or as specified in `DB_DATABASE`)
2. **User permissions**: The database user must have full privileges on the target database
3. **Network access**: 
   - For local databases: MySQL must accept connections from Docker containers
   - For remote databases: Firewall and security groups must allow connections from your server
4. **MySQL version**: Compatible with MySQL 5.7+ or MariaDB 10.3+

### Creating Database User (if needed)

For local MySQL installations, you may need to create a user with external connection privileges:

```sql
-- Connect to MySQL as root (mysql -u root -p)
CREATE DATABASE lowiska CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create user with a password of your choice
CREATE USER 'docker'@'%' IDENTIFIED BY 'your_chosen_password';
GRANT ALL PRIVILEGES ON lowiska.* TO 'docker'@'%';
FLUSH PRIVILEGES;
```

**Important**: Replace `your_chosen_password` with a secure password of your choice. This password must match the `DB_PASSWORD` value in your `.env` file.

**Security Note**: For production environments, use strong passwords and limit user privileges to only what's necessary.

## File Structure

- `Dockerfile.dev`: Development container with dev dependencies
- `Dockerfile.prod`: Production-optimized container with cached assets
- `docker-compose.yml`: Base multi-container orchestration
- `docker-compose.override.yml`: Development overrides (auto-loaded)
- `docker-compose.prod.yml`: Production overrides
- `.docker/vhost.conf`: Apache virtual host configuration with mod_rewrite
- `.dockerignore`: Files excluded from Docker context

## Quick Start

### Development Environment

**⚠️ Important: Follow these steps in exact order to avoid common issues**

1. **Copy and configure environment file:**
   ```bash
   cp .env.example .env
   ```
   
   **Important**: Update the `.env` file with your database configuration:
   
   - For **local XAMPP/WAMP**: Use `DB_HOST=host.docker.internal`
   - For **remote server**: Use your server's IP/hostname
   - For **cloud services**: Use the provided connection string
   - For **local MySQL**: Use `host.docker.internal` or appropriate gateway IP
   - **Set DB_PASSWORD**: Use the password you create for your database user (see Database Requirements section)
   
   Example configurations are provided in the Database Configuration Options section above.

2. **Build and start containers:**
   ```bash
   docker compose up -d --build
   ```
   
   **Why `--build`**: Ensures fresh images with all dependencies including `intl` extension.

3. **Generate application key:**
   ```bash
   docker compose exec app php artisan key:generate
   ```
   
   **Critical**: This must be done after containers are running, not before.

4. **Restart containers to load new APP_KEY:**
   ```bash
   docker compose down && docker compose up -d
   ```
   
   **Important**: Docker compose doesn't automatically reload environment variables when they change in `.env` file.

5. **Run database migrations:**
   ```bash
   docker compose exec app php artisan migrate
   ```

6. **Restart containers to ensure all services work with database:**
   ```bash
   docker compose down && docker compose up -d
   ```
   
   **Important**: Queue and scheduler containers need database tables to function properly. After migrations, restart ensures all services connect to the updated database.

   **Note**: If the queue container fails to start or exits with error, restart it separately:
   ```bash
   docker compose restart queue
   ```
   This may be needed if the queue container started before database tables were created.

7. **Clear cache (recommended):**
   ```bash
   docker compose exec app php artisan config:clear
   docker compose exec app php artisan cache:clear
   ```

8. **Optional - Run seeders (if they exist):**
   ```bash
   docker compose exec app php artisan db:seed
   ```

9. **Access the application:**
   - Open http://localhost:8000 in your browser
   - You should see the Laravel welcome page

### Verification

Check if everything is running correctly:

```bash
# Check container status (should show: app, queue, scheduler)
docker compose ps

# Check application response
curl -s -o /dev/null -w "HTTP Status: %{http_code}\n" http://localhost:8000

# View logs if needed
docker compose logs app
docker compose logs queue
docker compose logs scheduler
```

**Expected result**: All three containers (app, queue, scheduler) should be in "Up" status.

### Common Setup Issues (Fixed by following above order)

- ✅ **`intl` extension missing**: Fixed in Dockerfile.dev
- ✅ **"No application encryption key"**: Fixed by step 4 (key generation after containers start)
- ✅ **"Unsupported cipher"**: Fixed by proper key generation sequence
- ✅ **Database connection errors**: Fixed by using localhost MySQL configuration in .env.example
- ✅ **Permission errors**: Fixed by proper ownership settings in Dockerfile

### Production Environment

For production deployment:

1. Use production compose file:
   ```bash
   docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
   ```

2. Or remove the development override file and update environment:
   ```bash
   mv docker-compose.override.yml docker-compose.override.yml.bak
   APP_ENV=production docker compose up -d
   ```

## Container Details

### Main Application (app)
- **Port**: 8000 (mapped to container port 80)
- **Features**: PHP 8.3, Apache with mod_rewrite/headers/expires/deflate
- **Volumes**: `/storage` directory mounted for persistent data
- **Development**: Source code mounted for live editing

### Queue Worker (queue)
- **Purpose**: Processes background jobs from the queue
- **Command**: `php artisan queue:work --verbose --tries=3 --timeout=90`
- **Scaling**: Can be scaled with `docker-compose up --scale queue=3`
- **Database**: Connects to external MySQL database for queue storage

### Scheduler (scheduler)
- **Purpose**: Runs Laravel's task scheduler
- **Command**: Executes `php artisan schedule:run` every minute
- **Note**: Only run one instance to avoid duplicate scheduled tasks
- **Database**: Connects to external MySQL database for scheduled task logging

### External Database
- **Type**: MySQL 5.7+ or MariaDB 10.3+
- **Connection**: Via configured `DB_HOST` (local XAMPP, remote server, or cloud service)
- **Storage**: All application data persisted in external database
- **Backup**: Managed separately from Docker containers

## Apache Configuration

The `.docker/vhost.conf` includes:
- **mod_rewrite**: Enabled for Laravel routing
- **Security Headers**: X-Content-Type-Options, X-Frame-Options, X-XSS-Protection
- **Compression**: gzip compression for static assets
- **Caching**: Browser caching headers for static assets
- **Logging**: Error and access logs

### View Logs
```bash
# All services
docker compose logs -f

# Specific service
docker compose logs -f app
docker compose logs -f queue
docker compose logs -f scheduler
```

## Troubleshooting

### Common Issues and Solutions

1. **"No application encryption key has been specified"**
   ```bash
   # Solution: Generate key after containers are running
   docker compose exec app php artisan key:generate
   docker compose exec app php artisan config:clear
   ```

2. **"Unsupported cipher or incorrect key length"**
   ```bash
   # Solution: Clear corrupted key and regenerate
   docker compose exec app bash -c "rm /var/www/html/.env && cp /var/www/html/.env.example /var/www/html/.env"
   docker compose exec app php artisan key:generate --force
   docker compose exec app php artisan config:clear
   ```

3. **"intl extension is missing"**
   ```bash
   # Solution: Rebuild containers (fix is in Dockerfile.dev)
   docker compose down
   docker compose up -d --build --no-cache
   ```

4. **Database connection errors**
   
   **For local databases (XAMPP/WAMP):**
   ```bash
   # Ensure .env has correct settings:
   # DB_HOST=host.docker.internal
   # DB_USERNAME=docker (or root)
   # DB_PASSWORD=your_password
   
   # Test connection from container:
   docker compose exec app php artisan migrate:status
   ```
   
   **For remote databases:**
   ```bash
   # Check if database server is accessible:
   docker compose exec app bash -c "timeout 3 bash -c '</dev/tcp/your-db-host/3306'; echo $?"
   # Should return 0 if connection is successful
   
   # Verify credentials and database exists:
   docker compose exec app php artisan tinker --execute="DB::connection()->getPdo();"
   ```
   
5. **"Connection refused" to external database**
   ```bash
   # For local MySQL (XAMPP/WAMP):
   # 1. Ensure MySQL is running in XAMPP Control Panel
   # 2. Check if MySQL accepts external connections
   # 3. Create database user with external access:
   
   mysql -u root -p -e "
   CREATE USER 'docker'@'%' IDENTIFIED BY 'your_chosen_password';
   GRANT ALL PRIVILEGES ON łowiska.* TO 'docker'@'%';
   FLUSH PRIVILEGES;
   "
   ```

6. **Docker containers can't reach host database**
   ```bash
   # On Windows/Mac: Use host.docker.internal
   DB_HOST=host.docker.internal
   
   # On Linux: Use Docker gateway IP
   DB_HOST=172.17.0.1
   # or find gateway IP:
   docker network inspect bridge | grep Gateway
  
   ```

7. **Permission errors with storage/logs**
   ```bash
   # Fix file permissions
   docker compose exec app bash -c "chown -R www-data:www-data /var/www/html/storage"
   docker compose exec app bash -c "chown -R www-data:www-data /var/www/html/bootstrap/cache"
   ```

8. **Port conflicts**: Change port mapping if 8000 or 3306 are in use
   ```yaml
   ports:
     - "8001:80"  # Use port 8001 instead
   ```

9. **Asset building issues**: Rebuild containers
   ```bash
   docker compose down
   docker compose up --build
   ```

10. **Queue or scheduler containers not running/failing**
    ```bash
    # Check logs for specific error:
    docker compose logs queue
    docker compose logs scheduler
    
    # Common cause: Database tables missing
    # Solution: Run migrations and restart containers
    docker compose exec app php artisan migrate
    docker compose down && docker compose up -d
    
    # If queue container still fails (shows "Exited (1)" status):
    docker compose restart queue
    
    # Verify all containers are running:
    docker compose ps
    # Should show: app, queue, scheduler (all in "Up" status)
    ```
    
    **Note**: Queue and scheduler containers require database tables (cache, jobs, sessions) to function. They will fail if migrations haven't been run.
    
    **Common error**: `Table 'łowiska.cache' doesn't exist` - This happens when queue container starts before migrations are complete. Simply restart the queue container after migrations.

11. **Docker build fails with compiler errors (GCC internal error)**
    ```bash
    # Error during image build:
    # "internal compiler error: Segmentation fault" in mbstring compilation
    
    # Solution 1: Retry the build
    docker compose build --no-cache
    docker compose up -d
    
    # Solution 2: Build with more memory (if using limited resources)
    docker system prune -f  # Free up space first
    docker compose build --no-cache --memory=2g
    ```
    
    **Note**: This is a known issue with GCC compiler on some systems when building PHP extensions. Usually resolved by retrying the build or using slightly different PHP version.

### Emergency Reset

If nothing works, complete reset:
```bash
# Stop and remove everything
docker compose down -v
docker system prune -f

# Start fresh
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

### Rebuild Containers
```bash
# Rebuild all containers
docker compose down && docker compose up --build

# Rebuild specific service
docker compose build app && docker compose up -d app
```