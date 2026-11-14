<?php

namespace App\Services;

use GeminiAPI\Client;
use GeminiAPI\GenerationConfig;
use GeminiAPI\Resources\Parts\TextPart;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\PdfToText\Pdf;

class ResumeAnalysisService
{
    private const EXTRACTION_MODEL = 'gemini-3.5-flash-lite';
    private const ANALYSIS_MODEL   = 'gemini-3.5-flash';
    private const RESUME_KEYS      = ['summary', 'skills', 'experience', 'education'];

    /**
     * Extract structured information (summary, skills, experience, education)
     * from an uploaded resume PDF.
     */
    public function extractResumeInformation(string $storagePath): array
    {
        try {
            $startedAt = microtime(true);
            $rawText = $this->extractTextFromPdf($storagePath);
            Log::debug('PDF extraction took ' . round(microtime(true) - $startedAt, 2) . ' seconds');

            if (trim($rawText) === '') {
                throw new \Exception('No text could be extracted from the PDF.');
            }

            Log::debug('Extracted ' . strlen($rawText) . ' characters from resume PDF');

            $prompt = <<<PROMPT
You are a professional resume analysis assistant.

Analyze the following resume and extract the information.

Return ONLY a valid JSON object with exactly these four keys:

{
    "summary": "",
    "skills": "",
    "experience": "",
    "education": ""
}

Instructions:
- summary: Extract the candidate's professional summary.
- skills: Extract technical and soft skills.
- experience: Extract work experience, internships, and projects.
- education: Extract degrees, universities, and graduation details.
- All values must be plain strings, not arrays or nested objects.
- If information is not available, return an empty string.
- Do not add Markdown formatting.
- Do not include explanations outside the JSON object.

RESUME CONTENT:
{$rawText}
PROMPT;

            $parsed = $this->askGemini(self::EXTRACTION_MODEL, $prompt);

            $result = [];
            foreach (self::RESUME_KEYS as $key) {
                $value = $parsed[$key] ?? '';

                // If the model returned an array/object, flatten it to a string.
                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                }

                $result[$key] = (string) $value;
            }

            Log::debug('Parsed resume information: ' . json_encode($result, JSON_UNESCAPED_UNICODE));

            return $result;
        } catch (\Throwable $e) {
            Log::error('Resume analysis failed: ' . get_class($e) . ': ' . $e->getMessage());

            return array_fill_keys(self::RESUME_KEYS, '');
        }
    }

    /**
     * Score a candidate's resume against a job vacancy.
     *
     * @param  mixed         $jobVacancy  Model with title, description, location, type, salary.
     * @param  string|array  $resumeData  Parsed resume data (JSON string or array).
     * @return array{aiGeneratedScore: int, aiGeneratedFeedback: string}
     */
    public function analyzeResume($jobVacancy, $resumeData): array
    {
        try {
            $jobDetails = json_encode([
                'job_title'       => $jobVacancy->title,
                'job_description' => $jobVacancy->description,
                'job_location'    => $jobVacancy->location,
                'job_type'        => $jobVacancy->type,
                'job_salary'      => $jobVacancy->salary,
            ], JSON_UNESCAPED_UNICODE);

            // The controller may already pass a JSON string; don't encode it twice.
            if (!is_string($resumeData)) {
                $resumeData = json_encode($resumeData, JSON_UNESCAPED_UNICODE);
            }

            $prompt = <<<PROMPT
You are an expert HR professional and job recruiter.
You are given a job vacancy and a resume.
Analyze the resume and determine if the candidate is a good fit for the job.

Return ONLY a valid JSON object with exactly these keys:

{
    "aiGeneratedScore": 0,
    "aiGeneratedFeedback": ""
}

Instructions:
- aiGeneratedScore: an integer from 0 to 100 for the candidate's suitability for the job.
- aiGeneratedFeedback: detailed feedback specific to this job and this resume.
- Do not add Markdown formatting.
- Do not include explanations outside the JSON object.

JOB DETAILS:
{$jobDetails}

RESUME DATA:
{$resumeData}
PROMPT;

            $parsed = $this->askGemini(self::ANALYSIS_MODEL, $prompt);

            if (!isset($parsed['aiGeneratedScore'], $parsed['aiGeneratedFeedback'])) {
                throw new \Exception('Gemini response is missing required keys.');
            }

            return [
                'aiGeneratedScore'    => max(0, min(100, (int) $parsed['aiGeneratedScore'])),
                'aiGeneratedFeedback' => (string) $parsed['aiGeneratedFeedback'],
            ];
        } catch (\Throwable $e) {
            Log::error('Error analyzing resume: ' . get_class($e) . ': ' . $e->getMessage());

            return [
                'aiGeneratedScore'    => 0,
                'aiGeneratedFeedback' => 'An error occurred during resume analysis. Please try again later.',
            ];
        }
    }

    /**
     * Send a prompt to Gemini and return the response decoded as a JSON array.
     *
     * @throws \Throwable
     */
    private function askGemini(string $model, string $prompt): array
    {
        $apiKey = config('services.gemini.key');

        if (empty($apiKey)) {
            throw new \Exception('Gemini API key is missing from services configuration.');
        }

        $client = new Client($apiKey);

        $startedAt = microtime(true);

        $response = $client
            ->generativeModel($model)
            ->withGenerationConfig(
                (new GenerationConfig())
                    ->withTemperature(0.1)
                    ->withMaxOutputTokens(2048)
            )
            ->generateContent(new TextPart($prompt));

        Log::debug("Gemini ({$model}) call took " . round(microtime(true) - $startedAt, 2) . ' seconds');

        $json = $this->cleanJson($response->text());
        Log::debug('Gemini AI response: ' . $json);

        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new \Exception('Failed to parse Gemini response as JSON: ' . json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * Strip ```json ... ``` fences the model sometimes adds.
     */
    private function cleanJson(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        return trim($text);
    }

    /**
     * Extract text from an uploaded PDF stored on the public disk.
     */
    private function extractTextFromPdf(string $storagePath): string
    {
        $disk = Storage::disk('public');

        if (!$disk->exists($storagePath)) {
            throw new \Exception('File not found in storage: ' . $storagePath);
        }

        $binPath  = $this->findPdfToText();
        $tempFile = tempnam(sys_get_temp_dir(), 'resume_');

        try {
            file_put_contents($tempFile, $disk->get($storagePath));

            return (new Pdf($binPath))
                ->setPdf($tempFile)
                ->text();
        } finally {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * Locate the pdftotext executable.
     */
    private function findPdfToText(): string
    {
        $candidates = array_filter([
            env('PDFTOTEXT_PATH'),
            '/opt/homebrew/bin/pdftotext',
            '/usr/bin/pdftotext',
            '/usr/local/bin/pdftotext',
        ]);

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        throw new \Exception('pdftotext is not installed on the server.');
    }
}
