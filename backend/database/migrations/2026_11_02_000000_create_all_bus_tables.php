<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration unique - Projet academique de reservation de bus (une entreprise).
 *
 * PREVU UNIQUEMENT POUR UNE NOUVELLE BASE VIDE !
 * Ne pas laisser les migrations Laravel par defaut actives en parallele :
 * elles creeraient users/cache/jobs une seconde fois.
 *
 * Les donnees d'horaires fixes sont des heures locales Africa/Casablanca ;
 * l'application normalise ses instants absolus en UTC.
 *
 * Regles qui exigent des services Laravel (pas des simples cles etrangeres) :
 * - siege disponible sur toute la portion du voyage, gestion concurrente
 * - absence de chevauchement des affectations d'un bus / maintenance
 * - prix valide entre arrets du meme trajet dans le bon ordre
 * - paiement confirme avant generation du PDF / envoi de l'email
 * - annulation autorisee selon l'heure de montee du passager
 */
return new class extends Migration
{
    public function up(): void
    {
        // ================================================================
        // 01 - AUTHENTIFICATION : USERS + PASSWORD_RESET_TOKENS + SESSIONS
        // ================================================================
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password'); // Hash (Laravel Hash::make), jamais en clair
            $table->string('phone', 30)->nullable();
            $table->enum('role', ['admin', 'client'])->default('client');
            $table->enum('status', ['active', 'suspended'])->default('active');
            $table->rememberToken();
            $table->timestamps();
            $table->index(['role', 'status']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        // ================================================================
        // 02 - CACHE : CACHE + CACHE_LOCKS
        // ================================================================
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration')->index();
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->bigInteger('expiration')->index();
        });

        // ================================================================
        // 03 - QUEUES : JOBS + JOB_BATCHES + FAILED_JOBS
        //      Utiles pour les e-mails / generation de documents en arriere-plan
        // ================================================================
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('connection');
            $table->string('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
            $table->index(['connection', 'queue', 'failed_at']);
        });

        // ================================================================
        // 04 - FLOTTES : BUS & SIEGES
        // ================================================================
        Schema::create('buses', function (Blueprint $table) {
            $table->id();
            $table->string('registration_number', 50)->unique();
            $table->string('bus_name', 100)->nullable();
            $table->unsignedSmallInteger('capacity');
            $table->enum('status', ['active', 'maintenance', 'out_of_service'])->default('active');
            $table->timestamps();
        });

        Schema::create('bus_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bus_id')->constrained('buses')->restrictOnDelete();
            $table->string('seat_number', 10);
            $table->unsignedSmallInteger('row_number')->nullable();
            $table->unsignedTinyInteger('column_number')->nullable();
            $table->enum('seat_type', ['standard', 'premium'])->default('standard');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['bus_id', 'seat_number']);
        });

        Schema::create('equipments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
        });

        Schema::create('bus_equipments', function (Blueprint $table) {
            $table->foreignId('bus_id')->constrained('buses')->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipments')->restrictOnDelete();
            $table->primary(['bus_id', 'equipment_id']);
        });

        // ================================================================
        // 05 - RESEAU : STOPS, ROUTES & TARIFS
        // ================================================================
        Schema::create('stops', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('city', 120)->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->string('route_name', 200);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('route_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained('routes')->restrictOnDelete();
            $table->foreignId('stop_id')->constrained('stops')->restrictOnDelete();
            $table->unsignedSmallInteger('stop_order');
            $table->unsignedInteger('estimated_offset_minutes')->nullable();
            $table->unique(['route_id', 'stop_order']);
            $table->unique(['route_id', 'stop_id']);
        });

        Schema::create('route_fares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained('routes')->restrictOnDelete();
            $table->foreignId('from_route_stop_id')->constrained('route_stops')->restrictOnDelete();
            $table->foreignId('to_route_stop_id')->constrained('route_stops')->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('MAD');
            $table->unique(['route_id', 'from_route_stop_id', 'to_route_stop_id'], 'uq_route_fare_pair');
        });

        // ================================================================
        // 06 - PLANIFICATION : HORAIRES RECURRENTS & MAINTENANCE
        // ================================================================
        Schema::create('recurring_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained('routes')->restrictOnDelete();
            $table->foreignId('default_bus_id')->constrained('buses')->restrictOnDelete();
            $table->time('departure_time');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('recurring_schedule_days', function (Blueprint $table) {
            $table->foreignId('recurring_schedule_id')->constrained('recurring_schedules')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 1=lundi ... 7=dimanche
            $table->primary(['recurring_schedule_id', 'weekday'], 'pk_schedule_weekday');
        });

        Schema::create('bus_maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bus_id')->constrained('buses')->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->index(['bus_id', 'starts_at', 'ends_at'], 'idx_maintenance_window');
        });

        // ================================================================
        // 07 - VOYAGES REELS & HORAIRES DES ARRETS
        // ================================================================
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained('routes')->restrictOnDelete();
            $table->foreignId('recurring_schedule_id')->nullable()->constrained('recurring_schedules')->restrictOnDelete();
            $table->foreignId('bus_id')->constrained('buses')->restrictOnDelete();
            $table->dateTime('departure_at');
            $table->dateTime('expected_arrival_at');
            $table->dateTime('actual_departure_at')->nullable();
            $table->dateTime('actual_arrival_at')->nullable();
            $table->enum('status', ['scheduled', 'boarding', 'in_progress', 'completed', 'cancelled'])->default('scheduled');
            $table->unsignedSmallInteger('delay_minutes')->default(0);
            $table->timestamps();
            $table->unique(['recurring_schedule_id', 'departure_at'], 'uq_schedule_departure');
            $table->index(['route_id', 'departure_at']);
            $table->index(['bus_id', 'departure_at', 'expected_arrival_at'], 'idx_bus_trip_interval');
            $table->index(['status', 'departure_at']);
        });

        Schema::create('trip_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained('trips')->restrictOnDelete();
            $table->foreignId('route_stop_id')->constrained('route_stops')->restrictOnDelete();
            $table->unsignedSmallInteger('stop_order');
            $table->dateTime('scheduled_arrival_at')->nullable();
            $table->dateTime('scheduled_departure_at')->nullable();
            $table->dateTime('actual_arrival_at')->nullable();
            $table->dateTime('actual_departure_at')->nullable();
            $table->unique(['trip_id', 'stop_order']);
            $table->unique(['trip_id', 'route_stop_id']);
        });

        // ================================================================
        // 08 - RESERVATIONS PAR SEGMENTS & PAIEMENTS
        // ================================================================
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('trip_id')->constrained('trips')->restrictOnDelete();
            $table->foreignId('bus_seat_id')->constrained('bus_seats')->restrictOnDelete();
            $table->foreignId('from_trip_stop_id')->constrained('trip_stops')->restrictOnDelete();
            $table->foreignId('to_trip_stop_id')->constrained('trip_stops')->restrictOnDelete();
            $table->unsignedSmallInteger('from_stop_order');
            $table->unsignedSmallInteger('to_stop_order');
            $table->string('passenger_name', 150);
            $table->string('passenger_phone', 30)->nullable();
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('MAD');
            $table->enum('status', ['pending_payment', 'confirmed', 'cancellation_requested', 'cancelled', 'expired', 'payment_failed', 'completed', 'no_show'])->default('pending_payment');
            $table->dateTime('hold_expires_at')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['trip_id', 'bus_seat_id', 'status', 'from_stop_order', 'to_stop_order'], 'idx_seat_overlap');
            $table->index(['user_id', 'created_at'], 'idx_reservation_user');
            $table->index(['status', 'hold_expires_at'], 'idx_hold_expiry');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained('reservations')->restrictOnDelete();
            $table->enum('method', ['simulation', 'cash', 'gateway'])->default('simulation');
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('MAD');
            $table->enum('status', ['pending', 'paid', 'failed', 'refund_pending', 'refunded', 'refund_failed'])->default('pending');
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->string('provider_reference', 150)->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('refunded_at')->nullable();
            $table->timestamps();
            $table->index(['reservation_id', 'status']);
        });

        // ================================================================
        // 09 - BILLETS PDF + QR CODE, ANNULATIONS, DEMANDES & EMAIL
        // ================================================================
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained('reservations')->restrictOnDelete()->unique();
            $table->string('ticket_number', 60)->unique();
            $table->char('qr_token_hash', 64)->unique();
            $table->string('pdf_path', 500)->nullable();
            $table->enum('status', ['valid', 'used', 'void'])->default('valid');
            $table->dateTime('issued_at');
            $table->dateTime('scanned_at')->nullable();
            $table->foreignId('scanned_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('cancellation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained('reservations')->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('route_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('origin_city', 120);
            $table->string('destination_city', 120);
            $table->date('requested_for_date')->nullable();
            $table->time('requested_for_time')->nullable();
            $table->enum('request_type', ['new_route', 'no_departure'])->default('new_route');
            $table->enum('status', ['new', 'reviewing', 'accepted', 'rejected', 'notified'])->default('new');
            $table->foreignId('fulfilled_route_id')->nullable()->constrained('routes')->restrictOnDelete();
            $table->timestamps();
            $table->index(['origin_city', 'destination_city', 'requested_for_date', 'status'], 'idx_route_demand');
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->restrictOnDelete();
            $table->foreignId('route_request_id')->nullable()->constrained('route_requests')->restrictOnDelete();
            $table->enum('event_type', ['booking_confirmed', 'cancellation_update', 'trip_delayed', 'route_available']);
            $table->string('recipient', 255);
            $table->enum('status', ['queued', 'sent', 'delivered', 'failed'])->default('queued');
            $table->string('dedup_key', 150)->nullable()->unique();
            $table->string('provider_message_id', 190)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

    }

    public function down(): void
    {
        // L'ordre inverse evite les violations de foreign keys.
        foreach ([
            // Projet : notifications, ventes, voyages et referentiel
            'notification_logs',
            'route_requests',
            'cancellation_requests',
            'tickets',
            'payments',
            'reservations',
            'trip_stops',
            'trips',
            'bus_maintenances',
            'recurring_schedule_days',
            'recurring_schedules',
            'route_fares',
            'route_stops',
            'routes',
            'stops',
            'bus_equipments',
            'equipments',
            'bus_seats',
            'buses',

            // Queues et cache de Laravel
            'failed_jobs',
            'job_batches',
            'jobs',
            'cache_locks',
            'cache',

            // Authentication de Laravel
            'sessions',
            'password_reset_tokens',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
