<?php

namespace App\Console\Commands;

use App\Services\RecommendationTrainer;
use Illuminate\Console\Command;

class GenerateRecommendationsCommand extends Command
{
    protected $signature = 'recommendations:train
        {--factors=18 : Number of latent factors}
        {--iterations=15 : Training iterations}
        {--learning-rate=0.05 : SGD learning rate}
        {--regularization=0.02 : Regularization coefficient}
        {--top=40 : Number of items to store per user}';

    protected $description = 'Train collaborative filtering model and persist personalized recommendations';

    public function handle(RecommendationTrainer $trainer): int
    {
        $factors = max(2, (int) $this->option('factors'));
        $iterations = max(1, (int) $this->option('iterations'));
        $learningRate = max(0.0001, (float) $this->option('learning-rate'));
        $regularization = max(0.0001, (float) $this->option('regularization'));
        $top = max(1, (int) $this->option('top'));

        $this->info(sprintf(
            'Training collaborative filtering model (factors=%d, iterations=%d, lr=%.4f, reg=%.4f, top=%d)...',
            $factors,
            $iterations,
            $learningRate,
            $regularization,
            $top
        ));

        $updated = $trainer->train($factors, $iterations, $learningRate, $regularization, $top);

        if ($updated === 0) {
            $this->warn('No sufficient interaction data to train the model yet.');
        } else {
            $this->info("Generated ML-based recommendations for {$updated} users.");
        }

        return Command::SUCCESS;
    }
}
