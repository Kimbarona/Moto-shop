import { KeyRound } from 'lucide-react';
import {
    createContext,
    useCallback,
    useContext,
    useRef,
    useState,
} from 'react';
import type { FormEvent, ReactNode } from 'react';
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
import { toApiError } from '@/lib/api';

type PinRequest = { message: string; retry: boolean };

type PinContextValue = {
    /**
     * Run an API call; if the server answers "manager PIN required" (422 on "pin"),
     * ask for a PIN and run it again with that PIN. Resolves to null if cancelled.
     */
    withPin: <T>(run: (pin?: string) => Promise<T>) => Promise<T | null>;
};

const PinContext = createContext<PinContextValue | null>(null);

export function PinProvider({ children }: { children: ReactNode }) {
    const [request, setRequest] = useState<PinRequest | null>(null);
    const [pin, setPin] = useState('');
    const resolver = useRef<((pin: string | null) => void) | null>(null);

    const ask = useCallback((message: string, retry: boolean) => {
        setPin('');
        setRequest({ message, retry });

        return new Promise<string | null>((resolve) => {
            resolver.current = resolve;
        });
    }, []);

    const close = (value: string | null) => {
        resolver.current?.(value);
        resolver.current = null;
        setRequest(null);
    };

    const withPin = useCallback(
        async <T,>(run: (pin?: string) => Promise<T>): Promise<T | null> => {
            let current: string | undefined;

            for (;;) {
                try {
                    return await run(current);
                } catch (error) {
                    const apiError = toApiError(error);

                    if (!apiError.needsPin) {
                        throw apiError;
                    }

                    const entered = await ask(
                        apiError.errors.pin[0],
                        current !== undefined,
                    );

                    if (!entered) {
                        return null;
                    }

                    current = entered;
                }
            }
        },
        [ask],
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (/^\d{4,6}$/.test(pin)) {
            close(pin);
        }
    };

    return (
        <PinContext.Provider value={{ withPin }}>
            {children}

            <Dialog
                open={request !== null}
                onOpenChange={(open) => !open && close(null)}
            >
                <DialogContent className="sm:max-w-sm">
                    <form onSubmit={submit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2 font-display text-2xl">
                                <KeyRound className="size-6 text-hivis" />
                                Manager approval
                            </DialogTitle>
                            <DialogDescription
                                className={
                                    request?.retry
                                        ? 'font-medium text-destructive'
                                        : ''
                                }
                            >
                                {request?.message}
                            </DialogDescription>
                        </DialogHeader>

                        <Input
                            autoFocus
                            type="password"
                            inputMode="numeric"
                            autoComplete="off"
                            maxLength={6}
                            placeholder="••••"
                            value={pin}
                            onChange={(e) =>
                                setPin(e.target.value.replace(/\D/g, ''))
                            }
                            className="h-14 text-center font-display text-3xl tracking-[0.5em]"
                            aria-label="Manager PIN"
                        />
                        <p className="text-center text-xs text-muted-foreground">
                            A manager types their 4–6 digit PIN. The approval is
                            logged.
                        </p>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => close(null)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={!/^\d{4,6}$/.test(pin)}
                            >
                                Approve
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </PinContext.Provider>
    );
}

export function usePin(): PinContextValue {
    const context = useContext(PinContext);

    if (!context) {
        throw new Error('usePin must be used inside <PinProvider>.');
    }

    return context;
}
