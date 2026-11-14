<?php

namespace App\Http\Controllers;

use App\Models\JobApplication;

class JobApplicationsController extends Controller
{
    public function index()
    {
        $jobApplications = JobApplication::with(['jobVacancy.company', 'resume'])
            ->where('userId', auth()->id())
            ->latest()
            ->paginate(10);

        return view('job-applications.index', compact('jobApplications'));
    }
}
