// src/lib/api.ts

const API_BASE = import.meta.env.VITE_API_BASE_URL || '/api/mailbox.php';

export const mailboxApi = {
    getAccounts: async () => {
        const res = await fetch(`${API_BASE}?action=accounts`);
        return res.json();
    },
    addAccount: async (data: any) => {
        const res = await fetch(`${API_BASE}?action=accounts`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        return res.json();
    },
    removeAccount: async (id: number) => {
        const res = await fetch(`${API_BASE}?action=delete_account`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        return res.json();
    },
    getInbox: async (mailboxId?: number, limit: number = 30) => {
        const url = `${API_BASE}?action=list${mailboxId ? `&mailbox_id=${mailboxId}` : ''}&limit=${limit}`;
        const res = await fetch(url);
        return res.json();
    },
    getThreadMessages: async (threadId: number | string) => {
        const res = await fetch(`${API_BASE}?action=read&id=${threadId}`);
        return res.json();
    },
    readMessage: async (id: number | string) => {
        const res = await fetch(`${API_BASE}?action=read&id=${id}`);
        return res.json();
    },
    compose: async (data: { to: string; subject: string; body: string; mailbox_id?: number | null }) => {
        const res = await fetch(`${API_BASE}?action=compose`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        return res.json();
    },
    sendMessage: async (data: { to: string; subject: string; body: string; mailbox_id?: number | null }) => {
        const res = await fetch(`${API_BASE}?action=compose`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        return res.json();
    },
    getDrafts: async () => {
        const res = await fetch(`${API_BASE}?action=drafts`);
        return res.json();
    },
    approveDraft: async (id: number | string, bodyText?: string) => {
        const res = await fetch(`${API_BASE}?action=approve`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, bodyText })
        });
        return res.json();
    }
};
