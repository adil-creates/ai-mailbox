import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import AdminMailbox from '@/components/mailbox/AdminMailbox';
import { Toaster } from '@/components/ui/sonner';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      refetchOnWindowFocus: false,
      staleTime: 1000 * 60 * 2, // 2 minutes
    },
  },
});

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <div className="flex h-screen w-full flex-col bg-background text-foreground antialiased selection:bg-primary/20">
        <header className="border-b bg-card px-6 py-3.5 flex items-center justify-between shadow-xs">
          <div className="flex items-center gap-2.5">
            <div className="h-7 w-7 rounded-lg bg-primary flex items-center justify-center text-primary-foreground font-semibold text-sm shadow-xs">
              M
            </div>
            <div>
              <h1 className="font-semibold text-sm tracking-tight leading-none">AI Mailbox Pro</h1>
              <p className="text-[11px] text-muted-foreground mt-0.5">Self-Hosted Multi-Account Email & Warmup Client</p>
            </div>
          </div>
          <div className="flex items-center gap-3">
            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
              <span className="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
              IMAP & SMTP Active
            </span>
          </div>
        </header>

        <main className="flex-1 overflow-hidden">
          <AdminMailbox />
        </main>
      </div>
      <Toaster position="bottom-right" richColors />
    </QueryClientProvider>
  );
}
