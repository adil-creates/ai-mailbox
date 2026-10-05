import React, { useState, useEffect, useRef } from 'react';

import { useVirtualizer } from '@tanstack/react-virtual';
import { useQueryClient } from '@tanstack/react-query';
import DOMPurify from 'dompurify';
import {
    Search,
    Inbox,
    Send,
    Trash2,
    Archive,
    Star,
    AlertCircle,
    MoreVertical,
    Reply,
    Forward,
    Bot,
    RefreshCw,
    Mail,
    MailOpen,
    Plus,
    User,
    Flame,
    CalendarDays,
    TrendingUp,
    DollarSign,
    Activity,
    ChevronLeft,
    ChevronRight,
    PanelLeftClose,
    PanelLeftOpen,
    ArrowLeft,
    X
} from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Separator } from '@/components/ui/separator';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { toast } from '@/components/ui/sonner';
import { mailboxApi } from '@/lib/api';

interface Email {
    id: number | string;
    uid?: number;
    subject: string;
    from: string;
    date: string;
    timestamp?: number;
    is_read: boolean;
    has_attachments?: boolean;
    is_draft?: boolean;
    ai_confidence?: number;
    thread_count?: number;
    thread_id?: string;
    contact_email?: string;
}

interface EmailDetail extends Email {
    to: string;
    body: string;
    is_html: boolean;
}

export default function AdminMailbox() {
    const [emails, setEmails] = useState<Email[]>([]);
    const [selectedEmail, setSelectedEmail] = useState<any>(null);
    const [threadMessages, setThreadMessages] = useState<any[]>([]);
    const [replyText, setReplyText] = useState('');
    const [loading, setLoading] = useState(false);
    const [fetchingDetail, setFetchingDetail] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');
    const [activeFolder, setActiveFolder] = useState('inbox');
    const [isDraftingReply, setIsDraftingReply] = useState(false);
    const [aiLoading, setAiLoading] = useState(false);
    const [showCompose, setShowCompose] = useState(false);
    const [composeData, setComposeData] = useState({ to: '', subject: '', body: '' });
    const [selectedEmailIds, setSelectedEmailIds] = useState<Set<number | string>>(new Set());
    const queryClient = useQueryClient();
    const parentRef = useRef<HTMLDivElement>(null);
    const [composeSending, setComposeSending] = useState(false);
    const [accounts, setAccounts] = useState<any[]>([]);
    const [activeMailboxId, setActiveMailboxId] = useState<number | null>(null);
    const [showAddAccount, setShowAddAccount] = useState(false);
    const [addAccountData, setAddAccountData] = useState({ name: '', email: '', password: '', host: '', port: 993, smtp_host: '', smtp_port: 465 });
    const [addAccountLoading, setAddAccountLoading] = useState(false);
    const requestSentinel = React.useRef(0);
    const [isSidebarExpanded, setIsSidebarExpanded] = useState(false);

    const fetchEmails = async () => {
        const currentReq = Date.now();
        requestSentinel.current = currentReq;

        setLoading(true);
        try {
            const result = activeFolder === 'drafts'
                ? await mailboxApi.getDrafts()
                : await mailboxApi.getInbox(activeMailboxId || undefined, 20);

            if (requestSentinel.current === currentReq) {
                if (result.success) {
                    setEmails(result.data);
                } else {
                    toast({ title: 'Error', description: (result as any).error || 'Failed to load', variant: 'destructive' });
                }
            }
        } catch (error: any) {
            if (requestSentinel.current === currentReq) {
                toast({ title: 'Error', description: error?.message || 'Failed to fetch emails', variant: 'destructive' });
            }
        } finally {
            if (requestSentinel.current === currentReq) {
                setLoading(false);
            }
        }
    };

    const fetchAccounts = async () => {
        try {
            const res = await mailboxApi.getAccounts();
            if (res.success && res.data) {
                setAccounts(res.data);
                if (res.data.length > 0 && !activeMailboxId) {
                    setActiveMailboxId(res.data[0].id);
                }
            }
        } catch (e) {
            console.error(e);
        }
    };

    const handleRemoveAccount = async (e: React.MouseEvent, id: number) => {
        e.stopPropagation();
        if (!confirm('Are you sure you want to completely remove this mailbox account?')) return;
        try {
            const res = await mailboxApi.removeAccount(id);
            if (res.success) {
                toast({ title: 'Removed', description: 'Mailbox account has been removed.' });
                setAccounts(accounts.filter(a => a.id !== id));
                if (activeMailboxId === id) {
                    setActiveMailboxId(null);
                }
            } else {
                toast({ title: 'Error', description: res.error || 'Failed to remove', variant: 'destructive' });
            }
        } catch (err: any) {
            toast({ title: 'Error', description: err?.message || 'Failed to remove account', variant: 'destructive' });
        }
    };

    useEffect(() => {
        fetchAccounts();
    }, []);

    useEffect(() => {
        if (activeFolder !== 'drafts' && !activeMailboxId && accounts.length > 0) return; // Wait for activeMailboxId
        fetchEmails();
        setSelectedEmail(null);
    }, [activeFolder, activeMailboxId]);

    const handleSelectEmail = async (email: any) => {
        setSelectedEmail(email);
        setFetchingDetail(true);
        setThreadMessages([]); // Reset on select
        try {
            if (email.is_draft) {
                setThreadMessages([email]);
                return;
            }

            const data = await mailboxApi.readMessage(email.id, activeMailboxId || undefined);
            if (!email.is_read) {
                markAsRead(email.id);
            }
            if (data.is_thread && Array.isArray(data.data)) {
                setThreadMessages(data.data);
            } else if (data.data) {
                setThreadMessages([data.data]);
            }
        } catch (error: any) {
            toast({ title: 'Error', description: error?.message || 'Failed to load message', variant: 'destructive' });
        } finally {
            setFetchingDetail(false);
        }
    };

    const markAsRead = async (id: number | string) => {
        try {
            await mailboxApi.markAsRead(id);
            setEmails(emails.map(e => e.id === id ? { ...e, is_read: true } : e));
        } catch (error) {
            console.error('Failed to mark as read', error);
        }
    };

    const handleDelete = async (id: number | string) => {
        if (!confirm('Are you sure you want to delete this message?')) return;
        try {
            const isDraft = typeof id === 'string' && id.startsWith('draft_');
            const realId = isDraft ? id.replace('draft_', '') : String(id);

            const result = isDraft
                ? await mailboxApi.deleteDraft(realId)
                : await mailboxApi.deleteMessage(realId);

            if (result.success) {
                setEmails(emails.filter(e => e.id !== id));
                if (selectedEmail?.id === id) setSelectedEmail(null);
                toast({ title: 'Deleted', description: isDraft ? 'Draft deleted' : 'Message moved to trash' });
            }
        } catch (error: any) {
            toast({ title: 'Error', description: error?.message || 'Failed to delete message', variant: 'destructive' });
        }
    };

    const handleApproveDraft = async (id: string, bodyText: string) => {
        try {
            const realId = id.replace('draft_', '');
            const result = await mailboxApi.approveDraft(realId, bodyText);

            if (result.success) {
                toast({ title: 'Draft Approved', description: 'Added to the email queue' });
                setEmails(emails.filter(e => e.id !== id));
                setSelectedEmail(null);
            } else {
                toast({ title: 'Error', description: (result as any).error, variant: 'destructive' });
            }
        } catch (e: any) {
            toast({ title: 'Error', description: e?.message || 'Failed to approve draft', variant: 'destructive' });
        }
    };

    const handleComposeSend = async () => {
        if (!composeData.to || !composeData.subject || !composeData.body) {
            toast({ title: 'Missing fields', description: 'Please fill in To, Subject, and Body', variant: 'destructive' });
            return;
        }
        setComposeSending(true);
        try {
            const result = await mailboxApi.compose({
                ...composeData,
                mailbox_id: activeMailboxId || undefined
            });
            if (result.success) {
                toast({ title: 'Email Queued', description: 'Your email has been queued for sending.' });
                setShowCompose(false);
                setComposeData({ to: '', subject: '', body: '' });
            }
        } catch (e: any) {
            toast({ title: 'Error', description: e?.message || 'Failed to queue email', variant: 'destructive' });
        } finally {
            setComposeSending(false);
        }
    };

    const handleAiReply = () => {
        setAiLoading(true);
        setTimeout(() => {
            setAiLoading(false);
            setIsDraftingReply(true);
            toast({ title: 'AI Draft Ready', description: 'The AI has generated a response based on the conversation.' });
        }, 2000);
    };

    const handleSendReply = async () => {
        if (!replyText.trim()) return;
        setAiLoading(true); 
        try {
            // Find the contact email to reply to
            let toAddress = selectedEmail?.contact_email;
            if (!toAddress) {
                // Fallback: search thread for the first address that isn't 'You' or activeMailboxEmail
                const activeEmail = accounts.find(a => a.id === activeMailboxId)?.email || '';
                const otherMsg = [...threadMessages].reverse().find(m => m.from !== 'You' && m.from !== activeEmail);
                toAddress = otherMsg?.from || selectedEmail?.from;
            }

            const result = await mailboxApi.compose({
                to: toAddress,
                subject: selectedEmail?.subject?.startsWith('Re:') ? selectedEmail.subject : `Re: ${selectedEmail?.subject || 'Message'}`,
                body: replyText,
                mailbox_id: activeMailboxId || undefined
            });

            if (result.success) {
                toast({ title: 'Reply Sent', description: 'Your message has been sent successfully.' });
                setReplyText('');
                // Optimistically append to thread
                setThreadMessages(prev => [...prev, {
                    from: 'You',
                    date: new Date().toLocaleString(),
                    body: replyText,
                    is_read: true,
                    is_draft: false
                }]);
            } else {
                throw new Error(result.error || 'Failed to send');
            }
        } catch (error: any) {
            toast({ title: 'Error', description: error?.message || 'Failed to send reply', variant: 'destructive' });
        } finally {
            setAiLoading(false);
        }
    };

    const handleSmartAction = (action: string) => {
        toast({ title: 'Action Triggered', description: `${action} action has been initiated.` });
    };



    const handleAddAccount = async () => {
        if (!addAccountData.name || !addAccountData.email || !addAccountData.password || !addAccountData.host || !addAccountData.port) {
            toast({ title: 'Missing fields', description: 'Please fill in all fields', variant: 'destructive' });
            return;
        }
        setAddAccountLoading(true);
        try {
            const payload = {
                ...addAccountData,
                smtp_host: addAccountData.smtp_host.trim() || undefined,
                smtp_port: addAccountData.smtp_port || 465
            };
            const result = await mailboxApi.addAccount(payload);
            if (result.success) {
                toast({ title: 'Success', description: 'Account added successfully' });
                setShowAddAccount(false);
                setAddAccountData({ name: '', email: '', password: '', host: '', port: 993, smtp_host: '', smtp_port: 465 });
                fetchAccounts();
            } else {
                toast({ title: 'Error', description: (result as any).error || 'Failed to add account', variant: 'destructive' });
            }
        } catch (error: any) {
            toast({ title: 'Error', description: error?.message || 'Failed to add account', variant: 'destructive' });
        } finally {
            setAddAccountLoading(false);
        }
    };

    const handleDeleteAccount = async (e: React.MouseEvent, id: number) => {
        e.stopPropagation();
        if (!confirm('Are you sure you want to delete this mailbox account?')) return;
        try {
            const result = await mailboxApi.deleteAccount(id);
            if (result.success) {
                toast({ title: 'Success', description: 'Account deleted' });
                if (activeMailboxId === id) {
                    setActiveMailboxId(null);
                }
                fetchAccounts();
            } else {
                toast({ title: 'Error', description: (result as any).error, variant: 'destructive' });
            }
        } catch (error: any) {
            toast({ title: 'Error', description: error?.message, variant: 'destructive' });
        }
    };

    const filteredEmails = emails.filter(e =>
        e.subject.toLowerCase().includes(searchQuery.toLowerCase()) ||
        e.from.toLowerCase().includes(searchQuery.toLowerCase())
    );

    const rowVirtualizer = useVirtualizer({
        count: filteredEmails.length,
        getScrollElement: () => parentRef.current,
        estimateSize: () => 72,
        overscan: 10,
    });

    const toggleSelect = (id: number | string, e: React.MouseEvent) => {
        e.stopPropagation();
        const newSet = new Set(selectedEmailIds);
        if (newSet.has(id)) newSet.delete(id);
        else newSet.add(id);
        setSelectedEmailIds(newSet);
    };

    const prefetchEmailDetail = (id: number | string) => {
        // Proactive Hydration (Antigravity Rule 1)
        if (typeof id === 'string' && id.startsWith('draft_')) return;
        queryClient.prefetchQuery({
            queryKey: ['emailDetail', id],
            queryFn: () => mailboxApi.readMessage(id, activeMailboxId || undefined)
        });
    };

    return (
        <div className="flex flex-col h-full bg-background relative overflow-hidden">
            {/* TOP HEADER BAR */}
            <div className="flex items-center justify-between p-4 border-b shrink-0 bg-card">
                <div className="flex items-center gap-4">
                    <h1 className="text-xl font-bold tracking-tight">Mailbox</h1>
                    
                    {/* Mailbox Accounts Switcher (Capsule Tabs) */}
                    <div className="flex items-center gap-2">
                        {accounts.map(acc => (
                            <div
                                key={acc.id}
                                onClick={() => setActiveMailboxId(acc.id)}
                                className={cn(
                                    "flex items-center gap-2 px-3 py-1.5 rounded-full cursor-pointer transition-colors border text-sm group",
                                    activeMailboxId === acc.id
                                        ? "bg-primary text-primary-foreground border-primary"
                                        : "bg-background text-muted-foreground hover:bg-muted border-border"
                                )}
                            >
                                <Mail className="h-3.5 w-3.5" />
                                <span className="font-medium">{acc.email}</span>
                                <div 
                                    onClick={(e) => handleRemoveAccount(e, acc.id)}
                                    className={cn(
                                        "ml-1 p-0.5 rounded-full hover:bg-black/10 dark:hover:bg-white/20 transition-colors opacity-0 group-hover:opacity-100",
                                        activeMailboxId === acc.id ? "text-primary-foreground" : "text-muted-foreground"
                                    )}
                                >
                                    <X className="h-3 w-3" />
                                </div>
                            </div>
                        ))}
                        <TooltipProvider>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <div
                                        onClick={() => setShowAddAccount(true)}
                                        className="flex items-center justify-center h-8 w-8 rounded-full border border-dashed border-primary/50 text-primary cursor-pointer hover:bg-primary/10 transition-colors shrink-0"
                                    >
                                        <Plus className="h-4 w-4" />
                                    </div>
                                </TooltipTrigger>
                                <TooltipContent>Add Mailbox Account</TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </div>
                </div>

                {/* Right side Header Actions */}
                <div className="flex items-center gap-2">
                    {/* Deliverability Health Dialog Trigger */}
                    {(() => {
                        const activeAccount = accounts.find(a => a.id === activeMailboxId);
                        if (!activeAccount) return null;
                        const isHealthy = activeAccount.is_active === 1 || activeAccount.is_active === true;
                        
                        return (
                            <Dialog>
                                <DialogTrigger asChild>
                                    <Button variant="outline" size="sm" className={cn("gap-2", isHealthy ? "text-green-600 border-green-200 bg-green-50/50" : "text-red-600 border-red-200 bg-red-50/50")}>
                                        {isHealthy ? <Activity className="h-4 w-4" /> : <AlertCircle className="h-4 w-4" />}
                                        <span className="hidden sm:inline">Health: {isHealthy ? 'Good' : 'Paused'}</span>
                                    </Button>
                                </DialogTrigger>
                                <DialogContent className="max-w-xl">
                                    <DialogHeader>
                                        <DialogTitle>Mailbox Health & Deliverability</DialogTitle>
                                    </DialogHeader>
                                    <div className="p-4 flex flex-col gap-6">
                                        <div className="flex items-center gap-4">
                                            <div className={cn("p-3 rounded-full", isHealthy ? "bg-accent/20 text-accent" : "bg-red-500/20 text-red-500")}>
                                                {isHealthy ? <Activity className="h-6 w-6" /> : <AlertCircle className="h-6 w-6" />}
                                            </div>
                                            <div>
                                                <h3 className="font-semibold flex items-center gap-2">
                                                    Status: {isHealthy ? 'Excellent' : 'Paused'}
                                                    {activeAccount.health_score !== undefined && (
                                                        <Badge variant={activeAccount.health_score > 90 ? 'default' : 'destructive'} className="text-xs">
                                                            {parseFloat(activeAccount.health_score).toFixed(1)}/100
                                                        </Badge>
                                                    )}
                                                </h3>
                                                <p className="text-sm text-muted-foreground">
                                                    {isHealthy ? 'Safe to send. Auto-pause triggers are active.' : `Paused: ${activeAccount.pause_reason || 'Manual pause'}`}
                                                </p>
                                            </div>
                                        </div>
                                        
                                        <div className="grid grid-cols-3 gap-4 bg-muted/30 p-4 rounded-xl">
                                            <div className="text-center">
                                                <p className="text-xs text-muted-foreground uppercase tracking-wider font-semibold">Warmup Status</p>
                                                <div className="flex items-center gap-1 mt-1 justify-center">
                                                    <Flame className={cn("h-4 w-4", activeAccount.warmup_status === 'warming' ? 'text-orange-500' : 'text-accent')} />
                                                    <span className="font-medium capitalize text-sm">{activeAccount.warmup_status || 'not started'} {activeAccount.warmup_day ? `(Day ${activeAccount.warmup_day})` : ''}</span>
                                                </div>
                                            </div>
                                            <div className="text-center border-l border-r">
                                                <p className="text-xs text-muted-foreground uppercase tracking-wider font-semibold">Bounce Rate</p>
                                                <div className="flex items-center gap-1 mt-1 justify-center">
                                                    <span className={cn("font-medium text-sm", (activeAccount.bounce_rate || 0) > 2 ? 'text-red-500' : '')}>
                                                        {activeAccount.bounce_rate || '0.00'}%
                                                    </span>
                                                </div>
                                            </div>
                                            <div className="text-center min-w-[120px]">
                                                <div className="flex justify-between text-xs mb-1">
                                                    <span className="text-muted-foreground font-semibold">Daily Volume</span>
                                                    <span className="font-medium">{activeAccount.daily_sent || 0} / {activeAccount.daily_send_limit || 40}</span>
                                                </div>
                                                <div className="w-full bg-secondary h-2 rounded-full overflow-hidden">
                                                    <div 
                                                        className={cn("h-full rounded-full transition-all", ((activeAccount.daily_send_limit > 0 ? (activeAccount.daily_sent / activeAccount.daily_send_limit) * 100 : 0)) > 90 ? "bg-red-500" : "bg-primary")} 
                                                        style={{ width: `${Math.min(100, (activeAccount.daily_send_limit > 0 ? (activeAccount.daily_sent / activeAccount.daily_send_limit) * 100 : 0))}%` }} 
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </DialogContent>
                            </Dialog>
                        );
                    })()}
                    
                    <Separator orientation="vertical" className="h-6 hidden sm:block" />
                    
                    <Button variant="ghost" size="sm" onClick={() => fetchEmails()} disabled={loading} className="gap-2 hidden sm:flex">
                        <RefreshCw className={cn("h-4 w-4", loading && "animate-spin")} />
                        Sync
                    </Button>
                    
                    <Button size="sm" onClick={() => setShowCompose(true)} className="gap-2">
                        <Plus className="h-4 w-4" />
                        Compose
                    </Button>
                </div>
            </div>

            {/* Compose Modal */}
            {showCompose && (
                <div className="absolute inset-0 z-50 flex items-center justify-center bg-background/80 backdrop-blur-sm p-4">
                    <Card className="w-full max-w-2xl border-2 border-primary/30 shadow-2xl animate-in fade-in zoom-in-95">
                        <CardContent className="p-6 space-y-4">
                            <div className="flex items-center justify-between">
                                <h3 className="text-lg font-semibold">New Email</h3>
                                <Button variant="ghost" size="sm" onClick={() => setShowCompose(false)}>✕</Button>
                            </div>
                            <Input placeholder="To (email address)" value={composeData.to} onChange={(e) => setComposeData({ ...composeData, to: e.target.value })} />
                            <Input placeholder="Subject" value={composeData.subject} onChange={(e) => setComposeData({ ...composeData, subject: e.target.value })} />
                            <Textarea placeholder="Write your email body here... (HTML supported)" className="min-h-[250px]" value={composeData.body} onChange={(e) => setComposeData({ ...composeData, body: e.target.value })} />
                            <div className="flex justify-end gap-2">
                                <Button variant="ghost" onClick={() => setShowCompose(false)}>Cancel</Button>
                                <Button onClick={handleComposeSend} disabled={composeSending}>
                                    <Send className="mr-2 h-4 w-4" /> {composeSending ? 'Queuing...' : 'Queue for Sending'}
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            )}

            {/* Add Account Modal */}
            {showAddAccount && (
                <div className="absolute inset-0 z-50 flex items-center justify-center bg-background/80 backdrop-blur-sm p-4">
                    <Card className="w-full max-w-xl border-2 border-primary/30 shadow-2xl animate-in fade-in zoom-in-95">
                        <CardContent className="p-6 space-y-4">
                            <div className="flex items-center justify-between">
                                <h3 className="text-lg font-semibold">Add Mailbox Account</h3>
                                <Button variant="ghost" size="sm" onClick={() => setShowAddAccount(false)}>✕</Button>
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-2"><label className="text-sm font-medium">Display Name</label><Input placeholder="e.g. John Doe" value={addAccountData.name} onChange={(e) => setAddAccountData({ ...addAccountData, name: e.target.value })} /></div>
                                <div className="space-y-2"><label className="text-sm font-medium">Email Address</label><Input placeholder="e.g. hello@domain.com" value={addAccountData.email} onChange={(e) => setAddAccountData({ ...addAccountData, email: e.target.value })} /></div>
                                <div className="space-y-2"><label className="text-sm font-medium">App Password</label><Input type="password" placeholder="Enter password" value={addAccountData.password} onChange={(e) => setAddAccountData({ ...addAccountData, password: e.target.value })} /></div>
                                <div className="space-y-2"><label className="text-sm font-medium">IMAP Host</label><Input placeholder="e.g. imap.hostinger.com" value={addAccountData.host} onChange={(e) => setAddAccountData({ ...addAccountData, host: e.target.value })} /></div>
                                <div className="space-y-2"><label className="text-sm font-medium">IMAP Port (SSL)</label><Input type="number" placeholder="993" value={addAccountData.port} onChange={(e) => setAddAccountData({ ...addAccountData, port: parseInt(e.target.value) || 993 })} /></div>
                                <div className="space-y-2"><label className="text-sm font-medium">SMTP Host (Optional)</label><Input placeholder="e.g. smtp.hostinger.com" value={addAccountData.smtp_host} onChange={(e) => setAddAccountData({ ...addAccountData, smtp_host: e.target.value })} /></div>
                                <div className="space-y-2"><label className="text-sm font-medium">SMTP Port (SSL/TLS)</label><Input type="number" placeholder="465" value={addAccountData.smtp_port} onChange={(e) => setAddAccountData({ ...addAccountData, smtp_port: parseInt(e.target.value) || 465 })} /></div>
                            </div>
                            <div className="flex justify-end gap-2 pt-4">
                                <Button variant="ghost" onClick={() => setShowAddAccount(false)}>Cancel</Button>
                                <Button onClick={handleAddAccount} disabled={addAccountLoading}>{addAccountLoading ? 'Adding...' : 'Save Account'}</Button>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            )}

            {/* MAIN WORKSPACE: Sidebar + Content */}
            <div className="flex flex-1 overflow-hidden relative bg-muted/10">
                {/* Collapsible Sidebar */}
                <div 
                    className={cn(
                        "flex flex-col border-r bg-card transition-all duration-300 ease-in-out shrink-0", 
                        isSidebarExpanded ? "w-64" : "w-[68px]"
                    )}
                >
                    <div className="p-2 border-b flex justify-between items-center h-[57px]">
                        {isSidebarExpanded && <span className="font-semibold px-2 text-sm text-muted-foreground">Folders</span>}
                        <Button variant="ghost" size="icon" onClick={() => setIsSidebarExpanded(!isSidebarExpanded)} className="ml-auto text-muted-foreground hover:text-foreground">
                            {isSidebarExpanded ? <PanelLeftClose className="h-4 w-4" /> : <PanelLeftOpen className="h-4 w-4" />}
                        </Button>
                    </div>
                    
                    <div className="flex flex-col gap-1 p-2 py-3 overflow-y-auto">
                        {[
                            { id: 'inbox', icon: Inbox, label: 'Inbox', badge: emails.filter(e => !e.is_read).length },
                            { id: 'drafts', icon: Bot, label: 'AI Drafts', badge: activeFolder === 'drafts' ? emails.length : 0 },
                            { id: 'starred', icon: Star, label: 'Starred' },
                            { id: 'sent', icon: Send, label: 'Sent' },
                            { id: 'archive', icon: Archive, label: 'Archive' },
                            { id: 'trash', icon: Trash2, label: 'Trash' },
                        ].map((folder) => {
                            const Icon = folder.icon;
                            const isActive = activeFolder === folder.id;
                            return (
                                <TooltipProvider key={folder.id} delayDuration={0}>
                                    <Tooltip>
                                        <TooltipTrigger asChild>
                                            <Button
                                                variant={isActive ? "secondary" : "ghost"}
                                                className={cn(
                                                    "justify-start transition-colors h-10 relative",
                                                    isSidebarExpanded ? "px-3" : "px-0 justify-center",
                                                    isActive ? "bg-primary/10 text-primary hover:bg-primary/20" : "hover:bg-muted"
                                                )}
                                                onClick={() => {
                                                    setActiveFolder(folder.id);
                                                    setSelectedEmail(null);
                                                }}
                                            >
                                                <Icon className={cn("h-4 w-4", isSidebarExpanded && "mr-3", isActive ? "text-primary" : "text-muted-foreground")} />
                                                {isSidebarExpanded && (
                                                    <span className="flex-1 text-left font-medium">{folder.label}</span>
                                                )}
                                                {isSidebarExpanded && folder.badge ? (
                                                    <Badge className={cn("ml-auto border-none", isActive ? "bg-primary text-primary-foreground" : "bg-muted-foreground/20 text-foreground")}>
                                                        {folder.badge}
                                                    </Badge>
                                                ) : null}
                                                
                                                {/* Notification dot when collapsed */}
                                                {!isSidebarExpanded && folder.badge ? (
                                                    <span className="absolute top-2 right-2 h-2 w-2 rounded-full bg-primary" />
                                                ) : null}
                                            </Button>
                                        </TooltipTrigger>
                                        {!isSidebarExpanded && <TooltipContent side="right">{folder.label}</TooltipContent>}
                                    </Tooltip>
                                </TooltipProvider>
                            );
                        })}
                    </div>
                </div>

                {/* CONTENT AREA: Focused Reading Mode Container */}
                <div className="flex-1 flex overflow-hidden relative">
                    
                    {/* EMAIL LIST (Hidden if email is selected in mobile, or slides left. In Focused mode, we swap views entirely) */}
                    <div className={cn(
                        "flex-1 flex flex-col bg-card transition-all duration-300 absolute inset-0 z-10",
                        selectedEmail ? "-translate-x-full opacity-0 pointer-events-none" : "translate-x-0 opacity-100"
                    )}>
                        <div className="p-3 border-b flex items-center justify-between gap-4">
                            <div className="relative flex-1 max-w-md">
                                <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search emails..."
                                    className="pl-9 h-9 bg-muted/50 border-none text-sm focus-visible:ring-1"
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                />
                            </div>
                            
                            {selectedEmailIds.size > 0 && (
                                <div className="flex items-center gap-2 animate-in fade-in">
                                    <span className="text-sm font-medium text-muted-foreground">{selectedEmailIds.size} selected</span>
                                    <Button variant="destructive" size="sm" onClick={() => {
                                        toast({ title: 'Bulk Action', description: `Deleted ${selectedEmailIds.size} emails.`});
                                        setSelectedEmailIds(new Set());
                                    }}>
                                        <Trash2 className="mr-2 h-4 w-4" /> Delete
                                    </Button>
                                    <Button variant="ghost" size="sm" onClick={() => setSelectedEmailIds(new Set())}>
                                        Cancel
                                    </Button>
                                </div>
                            )}
                        </div>
                        
                        <div ref={parentRef} className="flex-1 overflow-auto bg-muted/5">
                            {filteredEmails.length === 0 ? (
                                <div className="h-full flex flex-col items-center justify-center text-muted-foreground">
                                    <Mail className="h-10 w-10 mb-4 opacity-20" />
                                    <p className="text-base font-medium">No messages found</p>
                                    <p className="text-sm">You're all caught up in {activeFolder}.</p>
                                </div>
                            ) : (
                                <div
                                    style={{
                                        height: `${rowVirtualizer.getTotalSize()}px`,
                                        width: '100%',
                                        position: 'relative',
                                    }}
                                >
                                    {rowVirtualizer.getVirtualItems().map((virtualRow) => {
                                        const email = filteredEmails[virtualRow.index];
                                        return (
                                            <div
                                                key={virtualRow.key}
                                                style={{
                                                    position: 'absolute',
                                                    top: 0,
                                                    left: 0,
                                                    width: '100%',
                                                    height: `${virtualRow.size}px`,
                                                    transform: `translateY(${virtualRow.start}px)`,
                                                }}
                                                className="px-4 py-1"
                                            >
                                                <button
                                                    onClick={() => handleSelectEmail(email)}
                                                    onMouseEnter={() => prefetchEmailDetail(email.id)}
                                                    className={cn(
                                                        "group w-full flex items-center justify-between p-3 text-left border rounded-lg hover:shadow-sm hover:border-border/80 transition-all bg-card cursor-pointer",
                                                        selectedEmailIds.has(email.id) ? "bg-primary/5 border-primary shadow-sm" : "border-transparent border-b-border/50",
                                                        !email.is_read ? "font-semibold bg-primary/[0.02]" : "text-muted-foreground"
                                                    )}
                                                >
                                                    <div className="flex items-center gap-4 min-w-0 flex-1">
                                                        <div className="shrink-0 flex items-center justify-center w-5" onClick={(e) => e.stopPropagation()}>
                                                            <input 
                                                                type="checkbox" 
                                                                checked={selectedEmailIds.has(email.id)}
                                                                onChange={(e) => toggleSelect(email.id, e as any)}
                                                                className={cn(
                                                                    "h-4 w-4 rounded border-gray-300 transition-opacity cursor-pointer", 
                                                                    selectedEmailIds.has(email.id) ? "opacity-100" : "opacity-0 group-hover:opacity-100"
                                                                )}
                                                            />
                                                            {!selectedEmailIds.has(email.id) && (
                                                                <div className="absolute opacity-100 group-hover:opacity-0 pointer-events-none">
                                                                    {!email.is_read ? <div className="h-2 w-2 rounded-full bg-primary" /> : null}
                                                                </div>
                                                            )}
                                                        </div>
                                                        
                                                        <div className="flex-shrink-0 w-48 truncate flex items-center gap-2">
                                                            <span className="truncate">{email.from.split('<')[0] || email.from}</span>
                                                            {email.thread_count && email.thread_count > 1 && (
                                                                <Badge variant="secondary" className="text-[9px] h-4 px-1.5 font-bold">
                                                                    {email.thread_count}
                                                                </Badge>
                                                            )}
                                                        </div>
                                                        
                                                        <div className="flex-1 truncate">
                                                            <span className={cn("text-sm", !email.is_read ? "text-foreground" : "")}>
                                                                {email.subject}
                                                            </span>
                                                        </div>
                                                    </div>
                                                    
                                                    <div className="shrink-0 text-xs text-muted-foreground whitespace-nowrap pl-4 group-hover:text-foreground transition-colors">
                                                        {email.date.split(' ').slice(0, 3).join(' ')}
                                                    </div>
                                                </button>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                    </div>

                    {/* EMAIL DETAIL (Focused Reading Mode) */}
                    <div className={cn(
                        "flex-1 flex flex-col bg-card transition-all duration-300 absolute inset-0 z-20 shadow-[-10px_0_30px_rgba(0,0,0,0.02)]",
                        selectedEmail ? "translate-x-0 opacity-100" : "translate-x-full opacity-0 pointer-events-none"
                    )}>
                        {fetchingDetail ? (
                            <div className="flex-1 flex items-center justify-center">
                                <div className="flex flex-col items-center gap-4 text-muted-foreground">
                                    <RefreshCw className="h-8 w-8 animate-spin" />
                                    <p className="text-sm font-medium animate-pulse">Loading conversation...</p>
                                </div>
                            </div>
                        ) : selectedEmail ? (
                            <div className="flex flex-col h-full overflow-hidden">
                                {/* Detail Toolbar */}
                                <div className="h-14 px-4 flex items-center justify-between border-b bg-card shrink-0">
                                    <div className="flex items-center gap-4">
                                        <Button variant="ghost" size="sm" onClick={() => setSelectedEmail(null)} className="gap-2 text-muted-foreground hover:text-foreground">
                                            <ArrowLeft className="h-4 w-4" />
                                            <span className="font-semibold">Back to Inbox</span>
                                        </Button>
                                        <Separator orientation="vertical" className="h-6" />
                                        <div className="flex items-center gap-1">
                                            {!selectedEmail.is_draft && (
                                                <TooltipProvider>
                                                    <Tooltip>
                                                        <TooltipTrigger asChild>
                                                            <Button variant="ghost" size="icon" onClick={handleAiReply} disabled={aiLoading} className="text-primary hover:text-primary hover:bg-primary/10">
                                                                <Bot className={cn("h-4 w-4", aiLoading && "animate-spin")} />
                                                            </Button>
                                                        </TooltipTrigger>
                                                        <TooltipContent>Draft AI Reply</TooltipContent>
                                                    </Tooltip>
                                                </TooltipProvider>
                                            )}
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-1 text-muted-foreground">
                                        {!selectedEmail.is_draft && (
                                            <>
                                                <Button variant="ghost" size="icon"><Reply className="h-4 w-4" /></Button>
                                                <Button variant="ghost" size="icon"><Forward className="h-4 w-4" /></Button>
                                            </>
                                        )}
                                        <Button variant="ghost" size="icon" className="hover:text-destructive hover:bg-destructive/10" onClick={() => handleDelete(selectedEmail.id)}>
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                        {!selectedEmail.is_draft && (
                                            <Button variant="ghost" size="icon"><MoreVertical className="h-4 w-4" /></Button>
                                        )}
                                    </div>
                                </div>

                                <ScrollArea className="flex-1 bg-background/50 relative">
                                    <div className="max-w-4xl mx-auto p-6 md:p-8 space-y-8 pb-40">
                                        {/* Email Subject Header */}
                                        <h1 className="text-2xl font-bold tracking-tight mb-8">
                                            {selectedEmail.subject}
                                        </h1>
                                        
                                        <div className="flex flex-col gap-6">
                                            {threadMessages.map((msg, idx) => {
                                                const isOutgoing = msg.is_draft || (msg.from && activeMailboxId && msg.from.includes('you'));
                                                
                                                return (
                                                    <div key={idx} className={cn("flex flex-col", isOutgoing ? "items-end" : "items-start")}>
                                                        <div className={cn(
                                                            "flex flex-col gap-1 w-full max-w-[85%] rounded-2xl p-5 shadow-sm border",
                                                            isOutgoing 
                                                                ? "bg-primary text-primary-foreground border-transparent rounded-tr-sm" 
                                                                : "bg-card border-border rounded-tl-sm"
                                                        )}>
                                                            <div className="flex items-center justify-between mb-4 pb-4 border-b border-current/10">
                                                                <div className="flex items-center gap-3">
                                                                    <Avatar className={cn("h-8 w-8 border", isOutgoing ? "border-primary-foreground/20" : "")}>
                                                                        <AvatarFallback className={isOutgoing ? "bg-primary-foreground/20 text-primary-foreground" : "bg-muted text-muted-foreground"}>
                                                                            {(msg.from || 'Y')[0].toUpperCase()}
                                                                        </AvatarFallback>
                                                                    </Avatar>
                                                                    <div>
                                                                        <p className="text-sm font-semibold">{msg.from || 'You'}</p>
                                                                        {msg.to && <p className="text-xs opacity-70">to {msg.to}</p>}
                                                                    </div>
                                                                </div>
                                                                <span className="text-xs opacity-70 font-medium">{msg.date}</span>
                                                            </div>
                                                            
                                                            {msg.is_draft ? (
                                                                <div className="space-y-4">
                                                                    <div className="flex items-center gap-3 mb-2">
                                                                        <Badge variant="secondary" className="bg-primary-foreground/20 text-primary-foreground hover:bg-primary-foreground/30 border-none">AI Generated Draft</Badge>
                                                                        {msg.ai_confidence !== undefined && msg.ai_confidence !== null && (
                                                                            <Badge variant="outline" className="border-primary-foreground/30 text-primary-foreground">
                                                                                {msg.ai_confidence}% Confidence Match
                                                                            </Badge>
                                                                        )}
                                                                    </div>
                                                                    <Textarea
                                                                        defaultValue={msg.body}
                                                                        onChange={(e) => {
                                                                            const newMsgs = [...threadMessages];
                                                                            newMsgs[idx].body = e.target.value;
                                                                            setThreadMessages(newMsgs);
                                                                        }}
                                                                        className="min-h-[200px] text-base w-full bg-primary-foreground/10 border-primary-foreground/20 text-primary-foreground placeholder:text-primary-foreground/50 focus-visible:ring-primary-foreground/30"
                                                                    />
                                                                    <div className="flex justify-end pt-2">
                                                                        <Button variant="secondary" size="sm" onClick={() => handleApproveDraft(String(msg.id), msg.body)} className="bg-primary-foreground text-primary hover:bg-primary-foreground/90 font-semibold shadow-sm">
                                                                            <Send className="w-4 h-4 mr-2" /> Queue for Sending
                                                                        </Button>
                                                                    </div>
                                                                </div>
                                                            ) : (
                                                                <div
                                                                    className={cn(
                                                                        "prose prose-sm md:prose-base max-w-none break-words", 
                                                                        isOutgoing 
                                                                            ? "dark:prose-invert prose-p:text-primary-foreground prose-headings:text-primary-foreground prose-a:text-primary-foreground text-primary-foreground" 
                                                                            : "dark:prose-invert text-foreground"
                                                                    )}
                                                                    dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(msg.body) }}
                                                                />
                                                            )}
                                                        </div>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </div>
                                </ScrollArea>
                                
                                {/* Inline Reply Box (Fixed at bottom of detail view) */}
                                <div className="absolute bottom-0 left-0 right-0 p-4 bg-background/80 backdrop-blur-md border-t border-border shadow-[0_-10px_20px_rgba(0,0,0,0.02)] z-10">
                                    <div className="max-w-4xl mx-auto flex flex-col gap-3">
                                        <Textarea 
                                            placeholder="Write your reply here..." 
                                            className="min-h-[100px] resize-none bg-card shadow-sm border-border focus-visible:ring-1 text-base" 
                                            value={replyText}
                                            onChange={(e) => setReplyText(e.target.value)}
                                        />
                                        <div className="flex justify-between items-center">
                                            <Button variant="secondary" onClick={handleAiReply} disabled={aiLoading} className="text-primary bg-primary/10 hover:bg-primary/20 hover:text-primary font-medium">
                                                <Bot className={cn("h-4 w-4 mr-2", aiLoading && "animate-spin")} /> Draft with AI
                                            </Button>
                                            <Button onClick={handleSendReply} disabled={aiLoading} className="shadow-sm">
                                                <Send className={cn("h-4 w-4 mr-2", aiLoading && "animate-spin")} /> Send Reply
                                            </Button>
                                        </div>
                                    </div>
                                </div>

                                {/* AI Drafting Indicator Overlay */}
                                {isDraftingReply && (
                                    <div className="absolute bottom-40 left-1/2 -translate-x-1/2 bg-card border shadow-xl rounded-xl p-4 w-[400px] animate-in slide-in-from-bottom-8 z-20">
                                        <div className="flex items-center gap-2 mb-3 text-xs font-bold text-primary tracking-wide">
                                            <Bot className="h-4 w-4" /> AI DRAFT READY
                                        </div>
                                        <div className="bg-muted/50 rounded-lg p-3 text-sm mb-4 italic text-foreground/80 line-clamp-3">
                                            "Hi there, thank you for reaching out. We've received your inquiry regarding the project scope..."
                                        </div>
                                        <div className="flex justify-end gap-2">
                                            <Button size="sm" variant="ghost" onClick={() => setIsDraftingReply(false)}>Discard</Button>
                                            <Button size="sm" onClick={() => setIsDraftingReply(false)} className="shadow-sm">Use Draft</Button>
                                        </div>
                                    </div>
                                )}
                            </div>
                        ) : null}
                    </div>
                </div>
            </div>
        </div>
    );
}
