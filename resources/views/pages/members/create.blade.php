{{-- Phase 18 Group A (D-4): every URL arrives from the view model; see
     pages/members/index.blade.php header. --}}
@extends('layouts.app', ['title' => 'Create Member'])

@section('content')
    @feature('member_loyalty')
        <div class="content-header mb-3">
            <div class="d-flex justify-content-between align-items-start w-100">
                <div>
                    <h1 class="page-title fw-bold">Create Member</h1>
                    <p class="page-description text-muted fs-7 mb-0">
                        <a href="{{ $indexUrl }}" class="text-muted text-decoration-none">
                            <i class="fas fa-arrow-left me-1"></i>Back to Member List</a>
                    </p>
                </div>
                <ol class="breadcrumb float-sm-end mb-0">
                    <li class="breadcrumb-item"><a href="{{ $indexUrl }}">Members</a></li>
                    <li class="breadcrumb-item active">Create Member</li>
                </ol>
            </div>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                <i class="fas fa-circle-exclamation me-1"></i>
                Please fix the errors below.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        <div class="row g-4">
            <div class="col-lg-8 col-12">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-transparent border-bottom py-3">
                        <h5 class="card-title mb-0 fw-semibold">New Member</h5>
                    </div>
                    <form method="POST" action="{{ $createUrl }}">
                        @csrf
                        <div class="card-body p-4">
                            <div class="mb-3">
                                <label for="name" class="form-label">Name</label>
                                <input type="text" name="name" id="name"
                                    class="form-control @error('name') is-invalid @enderror"
                                    value="{{ old('name') }}" placeholder="Full name">
                                @error('name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" name="email" id="email"
                                    class="form-control @error('email') is-invalid @enderror"
                                    value="{{ old('email') }}" placeholder="email@example.com">
                                @error('email')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <input type="text" name="username" id="username"
                                    class="form-control @error('username') is-invalid @enderror"
                                    value="{{ old('username') }}" placeholder="username">
                                @error('username')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-3">
                                <label for="member_number" class="form-label">Member Number</label>
                                {{-- Read-only by design: `Member::nextMemberNumber()` reserves the
                                     number inside the create transaction, from the
                                     `member_number_prefix` setting. A disabled input is never
                                     submitted, so nothing here reaches validation. --}}
                                <input type="text" id="member_number" class="form-control"
                                    value="{{ $memberNumber }}" disabled>
                                <small class="text-muted fs-7">Assigned automatically — the next number
                                    under the configured prefix.</small>
                            </div>

                            <div class="mb-3">
                                <label for="notes" class="form-label">Notes</label>
                                <textarea name="notes" id="notes" rows="4"
                                    class="form-control @error('notes') is-invalid @enderror">{{ old('notes') }}</textarea>
                                @error('notes')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="card-footer bg-body-tertiary border-top py-3 d-flex justify-content-end align-items-center gap-2">
                            <a href="{{ $indexUrl }}"
                                class="btn btn-outline-secondary d-inline-flex align-items-center gap-2">
                                <i class="bi bi-x-circle"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary d-inline-flex align-items-center gap-2">
                                <i class="bi bi-check-lg"></i> Create Member
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-lg-4 col-12">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-transparent border-bottom py-2">
                        <h5 class="card-title mb-0 fw-semibold">What happens next</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex align-items-start gap-2 mb-3">
                            <i class="fas fa-user-plus text-primary mt-1"></i>
                            <small class="text-muted">This creates a login account too — same as Add new user.
                                No password is typed here.</small>
                        </div>
                        <div class="d-flex align-items-start gap-2 mb-3">
                            <i class="fas fa-envelope text-primary mt-1"></i>
                            <small class="text-muted">They receive a temporary password and a verification link,
                                and must verify the email before the account is theirs.</small>
                        </div>
                        <div class="d-flex align-items-start gap-2 mb-3">
                            <i class="fas fa-id-badge text-primary mt-1"></i>
                            <small class="text-muted">The account gets the <b>member</b> role, which holds no
                                permissions. Tiers are assigned from the member's detail page.</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endfeature
@endsection
