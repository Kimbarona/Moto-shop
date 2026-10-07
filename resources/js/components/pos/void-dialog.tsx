import { Ban } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { usePin } from '@/hooks/use-pin';
import { api, toApiError } from '@/lib/api';
import type { Order } from '@/types/pos';

/**
 * Void with a reason. Cashiers are asked for a manager PIN by the server.
 */
type VoidDialogProps = {
    order: Order;
    open: boolean;
    onOpenChange: (v: boolean) => void;
    onVoided: (o: Order) => void;
};

/** Mounted only while open, so the reason starts empty every time. */
export function VoidDialog(props: VoidDialogProps) {
    return props.open ? <VoidForm {...props} /> : null;
}

function VoidForm({ order, open, onOpenChange, onVoided }: VoidDialogProps) {
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const { withPin } = usePin();

    const submit = async () => {
        setBusy(true);

        try {
            const result = await withPin((pin) =>
                api.voidOrder(order.id, { reason, pin }),
            );

            if (result) {
                toast.success(`${order.order_no} voided`);
                onVoided(result);
                onOpenChange(false);
            }
        } catch (e) {
            toast.error(toApiError(e).first());
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={(v) => !busy && onOpenChange(v)}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2 font-display text-2xl">
                        <Ban className="size-6 text-destructive" /> Void{' '}
                        {order.slip_no ?? order.order_no}
                    </DialogTitle>
                    <DialogDescription>
                        {order.status === 'paid'
                            ? 'Same-day only. Stock goes back, the payment and commission are reversed. The slip number stays used.'
                            : 'The parts reserved for this order are released.'}
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-1">
                    <Label htmlFor="void-reason">Reason</Label>
                    <Input
                        id="void-reason"
                        autoFocus
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        placeholder="e.g. Customer cancelled"
                    />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={busy}
                    >
                        Keep order
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        onClick={submit}
                        disabled={busy || reason.trim() === ''}
                    >
                        {busy && <Spinner />} Void order
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
