<?php

namespace App\Mcp\Resources;

use App\Mcp\Support\JobPostingPresenter;
use App\Mcp\Support\McpUserResolver;
use App\Mcp\Support\ToolException;
use App\Models\JobPosting;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Support\UriTemplate;

#[Description('Detalhe de uma vaga do usuário configurado: descrição, itens, métodos, sugestão, adaptação, candidatura e última tentativa.')]
class JobPostingResource extends JoboraResource implements HasUriTemplate
{
    public function __construct(
        McpUserResolver $users,
    ) {
        parent::__construct($users);
    }

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('jobora://job-postings/{id}');
    }

    protected function read(Request $request): Response
    {
        $posting = JobPosting::query()
            ->with(['items', 'applicationMethods', 'application'])
            ->where('user_id', $this->user()->id)
            ->whereKey((int) $request->get('id'))
            ->first();

        if ($posting === null) {
            throw ToolException::notFound('Vaga não encontrada.');
        }

        return $this->ok(['job_posting' => JobPostingPresenter::detail($posting, $this->language($request))]);
    }
}
