<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\ProductAssistant\AssistantSupport;
use Core\Controller;
use Core\Request;
use Core\Response;
use Core\Session;

class AssistantMessageController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->input('status', '');
        if (!is_string($status) || !in_array($status, ['waiting', 'answered', 'closed'], true)) {
            $status = '';
        }
        $threads = [];
        try {
            $threads = AssistantSupport::threads($status === '' ? null : $status);
        } catch (\Throwable) {
            Session::flash('error', 'Asistan mesajları şu anda listelenemiyor.');
        }

        return $this->view('back/assistant-messages/index', [
            'title'    => 'Asistan Mesajları',
            'threads'  => $threads,
            'status'   => $status,
            'waiting'  => AssistantSupport::waitingCount(),
            'success'  => Session::getFlash('success'),
            'error'    => Session::getFlash('error'),
        ]);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->param('id');
        $detail = AssistantSupport::thread($id);
        if ($detail === null) {
            Session::flash('error', 'Konuşma bulunamadı.');

            return $this->redirect(url('/admin/assistant-messages'));
        }

        return $this->view('back/assistant-messages/show', [
            'title'    => 'Asistan Mesajı',
            'thread'   => $detail['thread'],
            'messages' => $detail['messages'],
            'context'  => $detail['context'],
            'success'  => Session::getFlash('success'),
            'error'    => Session::getFlash('error'),
        ]);
    }

    public function reply(Request $request): Response
    {
        $id = (int) $request->param('id');
        $text = $request->input('body', '');
        if (!is_string($text) || !AssistantSupport::reply($id, $text)) {
            Session::flash('error', 'Cevap kaydedilemedi.');

            return $this->redirect(url('/admin/assistant-messages/' . $id));
        }
        Session::flash('success', 'Cevap iletildi.');

        return $this->redirect(url('/admin/assistant-messages/' . $id));
    }

    public function close(Request $request): Response
    {
        $id = (int) $request->param('id');
        AssistantSupport::close($id);
        Session::flash('success', 'Konuşma kapatıldı.');

        return $this->redirect(url('/admin/assistant-messages/' . $id));
    }
}
