<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('gdpr_consents', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->enum('value', ['yes', 'no']);
            $table->boolean('withdrawn')->default(false);
        });
    }

    public function down()
    {
        Schema::dropIfExists('gdpr_consents');
    }
};
